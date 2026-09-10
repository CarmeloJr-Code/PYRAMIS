<?php

namespace Tests;

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Illuminate\Support\Collection;
use Laravel\Dusk\Browser;
use Laravel\Dusk\TestCase as BaseTestCase;
use Livewire\Features\SupportTesting\DuskBrowserMacros;
use PHPUnit\Framework\Attributes\BeforeClass;

abstract class DuskTestCase extends BaseTestCase
{
    /**
     * Prepare for Dusk test execution.
     */
    #[BeforeClass]
    public static function prepare(): void
    {
        if (! static::runningInSail()) {
            static::startChromeDriver(['--port=9515']);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Livewire ships the waits its own browser tests use — for a commit to
        // come back, for a wire:navigate to land — but only registers them for
        // its own suite. Without them every press here would be followed by a
        // guessed pause, which is the shape flaky tests come in.
        Browser::mixin(new DuskBrowserMacros);

        $this->registerDateFieldMacro();
    }

    /**
     * Teach the browser to fill a date or datetime-local field.
     *
     * Typing into one means sending keystrokes in whatever order the browser's
     * locale puts the parts, which is a test that fails on a machine set to a
     * different region. Setting the value and announcing it is the same thing
     * the browser does, without the guesswork — and the events are what Livewire
     * is listening for, so the component sees the change either way.
     */
    private function registerDateFieldMacro(): void
    {
        Browser::macro('fillDateField', function (string $field, string $value): Browser {
            /** @var Browser $this */
            $this->script([
                sprintf(
                    <<<'JS'
                    const field = document.querySelector('input[name="%s"]');
                    field.value = '%s';
                    field.dispatchEvent(new Event('input', { bubbles: true }));
                    field.dispatchEvent(new Event('change', { bubbles: true }));
                    JS,
                    $field,
                    $value,
                ),
            ]);

            return $this;
        });
    }

    /**
     * Create the RemoteWebDriver instance.
     */
    protected function driver(): RemoteWebDriver
    {
        $options = (new ChromeOptions)->addArguments(collect([
            $this->shouldStartMaximized() ? '--start-maximized' : '--window-size=1920,1080',
            '--disable-search-engine-choice-screen',
            '--disable-smooth-scrolling',
            // Submitting the sign-in form otherwise raises Chrome's offer to
            // save the password, which takes the focus off the page. Every
            // click after that is delivered to a window that is not listening,
            // so the journey silently stops doing anything.
            '--disable-features=PasswordLeakDetection,AutofillServerCommunication',
            '--password-store=basic',
        ])->unless($this->hasHeadlessDisabled(), function (Collection $items) {
            return $items->merge([
                '--disable-gpu',
                '--headless=new',
            ]);
        })->all());

        $options->setExperimentalOption('prefs', [
            'credentials_enable_service' => false,
            'profile.password_manager_enabled' => false,
            'profile.password_manager_leak_detection' => false,
        ]);

        return RemoteWebDriver::create(
            $_ENV['DUSK_DRIVER_URL'] ?? env('DUSK_DRIVER_URL') ?? 'http://localhost:9515',
            DesiredCapabilities::chrome()->setCapability(
                ChromeOptions::CAPABILITY, $options
            )
        );
    }
}
