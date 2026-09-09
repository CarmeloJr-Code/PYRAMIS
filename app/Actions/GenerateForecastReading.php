<?php

namespace App\Actions;

use App\Ai\Agents\ForecastReadingAgent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;
use Throwable;

/**
 * Asks for a reading of the outlook, and refuses in every case where asking
 * would be wrong.
 *
 * The order matters. A server with no key never reaches the cache, because a
 * reading it can no longer reproduce is worse than none. A cached reading never
 * reaches the throttle, because reading something already paid for is free. And
 * nothing is written anywhere at any point — the reading is text a manager
 * weighs, and the only path from it to a change in the bakery runs through a
 * person (BR-008, BR-009).
 *
 * What comes back from a model is not trusted to be well formed. Everything is
 * coerced and capped before it is cached, so a runaway or half-built answer
 * cannot reach Blade, and a reading that came back empty is not kept — a
 * failure should be retryable, not served back forever.
 */
final class GenerateForecastReading
{
    /**
     * How many readings one manager may ask for in an hour.
     *
     * Groq's free allowance is 8,000 tokens a minute and 1,000 requests a day,
     * shared by everyone on the key. This is not a security control — it is a
     * courtesy to whoever presses the button next.
     */
    public const HOURLY_LIMIT = 6;

    /**
     * The most entries any one list is allowed to carry onto the page.
     *
     * @var array<string, int>
     */
    private const CAPS = [
        'key_factors' => 4,
        'production_recommendations' => 5,
        'inventory_recommendations' => 4,
    ];

    /**
     * The keys each kind of row must carry to be worth rendering.
     *
     * @var array<string, list<string>>
     */
    private const COLUMNS = [
        'key_factors' => ['factor', 'evidence'],
        'production_recommendations' => ['item', 'action', 'reason', 'priority'],
        'inventory_recommendations' => ['ingredient', 'action', 'reason', 'priority'],
    ];

    /**
     * Produce a reading of an outlook that has already been worked out.
     *
     * Takes the context rather than building it: the page has one in hand for
     * rendering, and BuildForecastContext is many queries to run twice.
     *
     * @param  array<string, mixed>  $context  As BuildForecastContext returned it.
     * @return array{summary: string, confidence: string, key_factors: list<array<string, string>>, production_recommendations: list<array<string, string>>, inventory_recommendations: list<array<string, string>>, generated_at: string, model: string, fingerprint: string}
     *
     * @throws RuntimeException when no key is configured, when this manager has
     *                          asked too often, when the provider refuses, or
     *                          when what came back is not a reading.
     */
    public function handle(array $context, User $manager, bool $refresh = false): array
    {
        if (blank(config('ai.providers.groq.key'))) {
            throw new RuntimeException(
                'AI readings are not switched on for this server. The figures above are unaffected.',
            );
        }

        $key = $this->cacheKey($context);

        if (! $refresh && Cache::has($key)) {
            return Cache::get($key);
        }

        $this->throttle($manager);

        $brief = app(CompactForecastContext::class)->handle($context);

        $reading = $this->ask($brief);

        $payload = $this->payload($reading, $brief);

        Cache::put($key, $payload, CarbonImmutable::now()->endOfDay());

        return $payload;
    }

    /**
     * The key a reading of this outlook is held under.
     *
     * Derived from the context rather than from what the caller asked for, so
     * the key can never name a window the reading does not describe. The window
     * ends today, so the lookback, the horizon and the date all fall out of
     * these three parts.
     *
     * @param  array<string, mixed>  $context
     */
    private function cacheKey(array $context): string
    {
        return sprintf(
            'forecast-reading:%s:%s:%d',
            $context['window']['from'],
            $context['window']['to'],
            $context['horizon']['days'],
        );
    }

    /**
     * Refuse a manager who has asked too many times this hour.
     *
     * Counted only where a reading would actually be sent for, so a run of
     * cache hits costs nobody anything.
     *
     * @throws RuntimeException
     */
    private function throttle(User $manager): void
    {
        $key = 'forecast-reading:'.$manager->id;

        if (RateLimiter::tooManyAttempts($key, self::HOURLY_LIMIT)) {
            throw new RuntimeException(sprintf(
                'You have asked for %d readings this hour, which is as many as the free allowance stretches to. The figures above are already up to date; try again in %d minutes.',
                self::HOURLY_LIMIT,
                (int) ceil(RateLimiter::availableIn($key) / 60),
            ));
        }

        RateLimiter::hit($key, 3600);
    }

    /**
     * Put the brief to the model, and turn any refusal into something a manager
     * can read.
     *
     * The provider's own message never reaches the screen — it names hosts and
     * models a baker has no use for — but it does reach the log, which is where
     * anyone debugging this will look.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    private function ask(string $brief): array
    {
        try {
            $response = (new ForecastReadingAgent)->prompt($brief);

            // The agent declares a schema, so a structured response is what it
            // should come back with. If the model answered in prose instead,
            // there is nothing here to render and saying so beats guessing.
            if (! $response instanceof StructuredAgentResponse) {
                throw new RuntimeException('The reading did not come back in the shape it was asked for.');
            }

            return $response->toArray();
        } catch (Throwable $exception) {
            Log::warning('A forecast reading could not be produced.', [
                'model' => ForecastReadingAgent::MODEL,
                'exception' => $exception,
            ]);

            throw new RuntimeException($this->explain($exception), previous: $exception);
        }
    }

    /**
     * What to tell a manager when the provider would not answer.
     *
     * Every one of these ends the same way, because it is the part that
     * matters: the figures on the page were worked out from their own records
     * and are untouched by any of this.
     */
    private function explain(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof RateLimitedException => 'The free daily allowance for AI readings has run out, or too many have been asked for at once. The figures above are unaffected — try again in a minute.',
            $exception instanceof ProviderOverloadedException => 'The AI service is busy just now. The figures above are unaffected — try again in a minute.',
            $exception instanceof ProviderConnectionException => 'PYRAMIS could not reach the AI service. The figures above are unaffected — they need no connection at all.',
            default => 'The reading could not be produced just now. The figures above are unaffected.',
        };
    }

    /**
     * What is safe to cache and show, out of whatever came back.
     *
     * @param  array<string, mixed>  $reading
     * @return array{summary: string, confidence: string, key_factors: list<array<string, string>>, production_recommendations: list<array<string, string>>, inventory_recommendations: list<array<string, string>>, generated_at: string, model: string, fingerprint: string}
     *
     * @throws RuntimeException
     */
    private function payload(array $reading, string $brief): array
    {
        $summary = trim((string) ($reading['summary'] ?? ''));

        if ($summary === '') {
            throw new RuntimeException(
                'The reading came back empty. Nothing has been kept, so asking again is worth a try.',
            );
        }

        return [
            'summary' => $summary,
            'confidence' => (string) ($reading['confidence'] ?? ''),
            'key_factors' => $this->rows($reading, 'key_factors'),
            'production_recommendations' => $this->rows($reading, 'production_recommendations'),
            'inventory_recommendations' => $this->rows($reading, 'inventory_recommendations'),
            // Ours, never the model's. This is the provenance a manager needs to
            // judge what they are reading, so it may not be something the model
            // could get wrong.
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'model' => ForecastReadingAgent::MODEL,
            // What the figures looked like when this was written, so the page
            // can notice later that they have moved on.
            'fingerprint' => sha1($brief),
        ];
    }

    /**
     * One of the lists, with anything half-built dropped and the rest capped.
     *
     * @param  array<string, mixed>  $reading
     * @return list<array<string, string>>
     */
    private function rows(array $reading, string $field): array
    {
        $rows = $reading[$field] ?? [];

        if (! is_array($rows)) {
            return [];
        }

        $kept = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $columns = [];

            foreach (self::COLUMNS[$field] as $column) {
                $value = $row[$column] ?? null;

                if (! is_scalar($value) || trim((string) $value) === '') {
                    continue 2;
                }

                $columns[$column] = trim((string) $value);
            }

            $kept[] = $columns;
        }

        return array_slice($kept, 0, self::CAPS[$field]);
    }
}
