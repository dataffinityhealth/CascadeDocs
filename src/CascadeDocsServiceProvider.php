<?php

namespace Lumiio\CascadeDocs;

use Lumiio\CascadeDocs\Commands\Documentation\AnalyzeModuleAssignmentsCommand;
use Lumiio\CascadeDocs\Commands\Documentation\AssignFilesToModulesCommand;
use Lumiio\CascadeDocs\Commands\Documentation\CreateModuleCommand;
use Lumiio\CascadeDocs\Commands\Documentation\GenerateAiDocumentationForAllFilesCommand;
use Lumiio\CascadeDocs\Commands\Documentation\GenerateAiDocumentationForFilamentFilesCommand;
use Lumiio\CascadeDocs\Commands\Documentation\GenerateArchitectureDocumentationCommand;
use Lumiio\CascadeDocs\Commands\Documentation\GenerateClassDocumentationCommand;
use Lumiio\CascadeDocs\Commands\Documentation\GenerateModuleDocumentationCommand;
use Lumiio\CascadeDocs\Commands\Documentation\GenerateModuleIndexCommand;
use Lumiio\CascadeDocs\Commands\Documentation\ModuleStatusCommand;
use Lumiio\CascadeDocs\Commands\Documentation\SyncModuleAssignmentsCommand;
use Lumiio\CascadeDocs\Commands\Documentation\UpdateAllModuleDocumentationCommand;
use Lumiio\CascadeDocs\Commands\Documentation\UpdateChangedDocumentationCommand;
use Lumiio\CascadeDocs\Commands\Documentation\UpdateDocumentationAfterMergeCommand;
use Lumiio\CascadeDocs\Commands\Documentation\UpdateDocumentationForChangedFilesCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class CascadeDocsServiceProvider extends PackageServiceProvider
{
    public function register(): void
    {
        parent::register();

        // Register services
        $this->app->singleton(\Lumiio\CascadeDocs\Services\Documentation\DocumentationParser::class);
        $this->app->singleton(\Lumiio\CascadeDocs\Services\Documentation\DocumentationDiffService::class);
        $this->app->singleton(\Lumiio\CascadeDocs\Services\Documentation\ModuleAssignmentService::class);
        $this->app->singleton(\Lumiio\CascadeDocs\Services\Documentation\ModuleAssignmentAIService::class);
        $this->app->singleton(\Lumiio\CascadeDocs\Services\Documentation\ModuleMetadataService::class);
        $this->app->singleton(\Lumiio\CascadeDocs\Services\Documentation\ModuleFileUpdater::class);
        $this->app->singleton(\Lumiio\CascadeDocs\Services\Documentation\ModuleMappingService::class);

        // Register main class
        $this->app->singleton(\Lumiio\CascadeDocs\CascadeDocs::class);
    }

    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('cascadedocs')
            ->hasConfigFile()
            ->hasCommands([
                // Original commands
                AnalyzeModuleAssignmentsCommand::class,
                AssignFilesToModulesCommand::class,
                CreateModuleCommand::class,
                GenerateAiDocumentationForAllFilesCommand::class,
                GenerateAiDocumentationForFilamentFilesCommand::class,
                ModuleStatusCommand::class,
                SyncModuleAssignmentsCommand::class,
                UpdateAllModuleDocumentationCommand::class,
                UpdateDocumentationAfterMergeCommand::class,
                UpdateDocumentationForChangedFilesCommand::class,
                // New simplified commands
                GenerateClassDocumentationCommand::class,
                GenerateModuleDocumentationCommand::class,
                GenerateArchitectureDocumentationCommand::class,
                UpdateChangedDocumentationCommand::class,
                GenerateModuleIndexCommand::class,
            ]);
    }
}
