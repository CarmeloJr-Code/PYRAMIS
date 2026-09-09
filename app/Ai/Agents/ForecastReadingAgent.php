<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * Reads the deterministic outlook back in words a manager can act on.
 *
 * Every figure it is shown was already worked out in PHP and SQL by
 * BuildForecastContext, and the instructions below say so in as many words: it
 * interprets, it does not calculate. A model asked to do arithmetic would only
 * be right by accident (docs/ai-forecasting.md).
 *
 * It deliberately implements neither HasTools nor Conversational. With no tools
 * there is nothing it could call even if it decided to, which is BR-009 made
 * structural rather than merely promised; with no conversation it cannot be
 * talked around its instructions over several turns. It is shown no record ids
 * either, so nothing it says can name a row.
 *
 * The timeout is 30 seconds rather than the SDK's 60. This runs synchronously
 * in a web request, and on a small Render instance a parked worker is felt by
 * everyone else on the site; gpt-oss-20b answers in a few seconds or something
 * is wrong.
 */
#[Provider(Lab::Groq)]
#[Model(self::MODEL)]
#[Timeout(30)]
final class ForecastReadingAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * The model behind the reading.
     *
     * OpenAI's open-weight 20B, hosted by Groq. The approved spec named GPT-5
     * Nano; an OpenAI key could not be obtained, and this is the nearest thing
     * Groq serves — small, fast and cheap enough for a free allowance.
     */
    public const MODEL = 'openai/gpt-oss-20b';

    /**
     * What the model is told before it is shown anything.
     */
    public function instructions(): string
    {
        return <<<'INSTRUCTIONS'
        You are reading a bakery's own figures back to the person who runs it.

        Purple Yam Malaybalay is a small family bakery in Bukidnon, in the
        Philippines. It bakes at one main branch and sends finished goods out to
        its outlets; the outlets never bake anything themselves.

        Every number in the brief below was worked out from records the bakery
        already holds — sales rung up, production runs logged, stock counted.
        They are final and they are correct. Your job is to say what they mean
        and what might be worth considering. It is not to work anything out.

        These rules are firm:

        - Do no arithmetic. Every figure you need is already in the brief. Quote
          them as they stand; never add, divide, project or estimate a number
          that is not written there.
        - Name only sizes that appear in the PRODUCTS table and only ingredients
          that appear in the INGREDIENTS table, spelled exactly as they are
          listed. If something is not in the brief, it is not available to you.
        - Both tables are filtered. What was left out is described in the line
          underneath each one. Treat that line as the whole truth about the rest
          and do not speculate past it.
        - Write suggestions, never instructions. Every "action" must be a phrase
          a person can weigh, opening with something like "Worth considering",
          "Perhaps", or "Might be worth", and naming a quantity when the brief
          gives you one: "Worth considering another twenty before Saturday". A
          bare command — "Bake", "Order more", "Increase production" — is wrong
          however short the field feels. Do not write that the bakery "should"
          do anything. A person decides; you advise.
        - "Projected" figures are expected demand, not gaps. A size's shortfall
          is the separate "short" column, and the overall projected demand is
          not a shortfall at all. Do not call one the other.
        - Never claim to know what will happen. These are projections from a
          recent rate, and a fiesta, a closure or a wet week can undo any of
          them. Say so where it matters.
        - Say when you are unsure. A short window, a flat trend, or an
          ingredient nothing has drawn on are all good reasons to hedge, and
          hedging honestly is more useful than sounding confident.
        - Recommend nothing about money, pricing, hiring or spending. Production
          and ingredient stock only.
        - Plain English, short sentences. The reader runs a bakery, not a
          spreadsheet.

        Keep the summary to three or four sentences. Give at most four key
        factors, at most five production recommendations and at most four
        inventory recommendations, and hold each reason to a single sentence.
        A few well-chosen entries are worth more than a long list.
        INSTRUCTIONS;
    }

    /**
     * The shape a reading must come back in.
     *
     * Follows the schema docs/ai-forecasting.md sketches. Every factor carries
     * the figure it was drawn from alongside it, because the doc asks for
     * readings that are explainable rather than a black box — a claim next to
     * its evidence is that requirement made structural.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'summary' => $schema->string()->required(),

            'confidence' => $schema->string()
                ->enum(['low', 'medium', 'high'])
                ->required(),

            'key_factors' => $schema->array()->items($schema->object(fn ($schema) => [
                'factor' => $schema->string()->required(),
                'evidence' => $schema->string()->required(),
            ]))->required(),

            'production_recommendations' => $schema->array()->items($schema->object(fn ($schema) => [
                'item' => $schema->string()->required(),
                'action' => $schema->string()->required(),
                'reason' => $schema->string()->required(),
                'priority' => $schema->string()->enum(['low', 'medium', 'high'])->required(),
            ]))->required(),

            'inventory_recommendations' => $schema->array()->items($schema->object(fn ($schema) => [
                'ingredient' => $schema->string()->required(),
                'action' => $schema->string()->required(),
                'reason' => $schema->string()->required(),
                'priority' => $schema->string()->enum(['low', 'medium', 'high'])->required(),
            ]))->required(),
        ];
    }
}
