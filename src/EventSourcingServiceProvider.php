<?php

namespace Spatie\EventSourcing;

use Illuminate\Support\Facades\Event;
use Spatie\EventSourcing\Console\CacheEventHandlersCommand;
use Spatie\EventSourcing\Console\ClearCachedEventHandlersCommand;
use Spatie\EventSourcing\Console\ListCommand;
use Spatie\EventSourcing\Console\MakeAggregateCommand;
use Spatie\EventSourcing\Console\MakeProjectorCommand;
use Spatie\EventSourcing\Console\MakeReactorCommand;
use Spatie\EventSourcing\Console\MakeStorableEventCommand;
use Spatie\EventSourcing\Console\ReplayCommand;
use Spatie\EventSourcing\EventSerializers\EventSerializer;
use Spatie\EventSourcing\StoredEvents\EventSubscriber;
use Spatie\EventSourcing\StoredEvents\Repositories\StoredEventRepository;
use Spatie\EventSourcing\Support\Composer;
use Spatie\EventSourcing\Support\DiscoverEventHandlers;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class EventSourcingServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-event-sourcing')
            ->hasConfigFile()
            ->hasMigrations([
                'create_stored_events_table',
                'create_snapshots_table',
            ])
            ->hasCommands([
                CacheEventHandlersCommand::class,
                ClearCachedEventHandlersCommand::class,
                ListCommand::class,
                MakeAggregateCommand::class,
                MakeProjectorCommand::class,
                MakeReactorCommand::class,
                MakeStorableEventCommand::class,
                ReplayCommand::class,
            ]);
    }

    public function packageBooted(): void
    {
        Event::subscribe(EventSubscriber::class);

        $this->discoverEventHandlers();
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(Projectionist::class, function () {
            $config = config('event-sourcing');

            $projectionist = new Projectionist($config);

            $projectionist
                ->addProjectors($config['projectors'] ?? [])
                ->addReactors($config['reactors'] ?? []);

            return $projectionist;
        });

        $this->app->alias(Projectionist::class, 'event-sourcing');

        $this->app->singleton(StoredEventRepository::class, config('event-sourcing.stored_event_repository'));

        $this->app->singleton(EventSubscriber::class, fn () => new EventSubscriber(config('event-sourcing.stored_event_repository')));

        $this->app
            ->when(ReplayCommand::class)
            ->needs('$storedEventModelClass')
            ->give(config('event-sourcing.stored_event_repository'));

        $this->app->bind(EventSerializer::class, config('event-sourcing.event_serializer'));
    }

    protected function discoverEventHandlers()
    {
        $projectionist = app(Projectionist::class);

        $cachedEventHandlers = $this->getCachedEventHandlers();

        if (! is_null($cachedEventHandlers)) {
            $projectionist->addEventHandlers($cachedEventHandlers);

            return;
        }

        (new DiscoverEventHandlers())
            ->within(config('event-sourcing.auto_discover_projectors_and_reactors'))
            ->useBasePath(config('event-sourcing.auto_discover_base_path', base_path()))
            ->ignoringFiles(
                array_merge(
                    Composer::getAutoloadedFiles(base_path('composer.json')),
                    config('event-sourcing.auto_discover_ignore_files', [])
                )
            )
            ->addToProjectionist($projectionist);
    }

    protected function getCachedEventHandlers(): ?array
    {
        $cachedEventHandlersPath = config('event-sourcing.cache_path').'/event-handlers.php';

        if (! file_exists($cachedEventHandlersPath)) {
            return null;
        }

        return require $cachedEventHandlersPath;
    }
}
