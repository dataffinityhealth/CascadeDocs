<?php

namespace Lumiio\CascadeDocs\Jobs\Documentation;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Lumiio\CascadeDocs\Services\Documentation\DocumentationDiffService;
use Lumiio\CascadeDocs\Services\Documentation\ModuleMappingService;

class GenerateAndTrackDocumentationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $file_path,
        public string $to_sha,
        public string $model = 'o3'
    ) {}

    public function handle(): void
    {
        // First, generate the documentation
        GenerateAiDocumentationForFileJob::dispatchSync($this->file_path, 'all', $this->model);

        // Then update our tracking log
        $this->update_file_in_log();

        // Check for module assignment
        $this->check_module_assignment();
    }

    protected function update_file_in_log(): void
    {
        $diff_service = new DocumentationDiffService;
        $log = $diff_service->load_update_log();

        $relative_path = Str::after($this->file_path, base_path().DIRECTORY_SEPARATOR);
        $current_sha = $diff_service->get_file_last_commit_sha($this->file_path) ?? $this->to_sha;

        $log['files'][$relative_path] = [
            'sha' => $current_sha,
            'last_updated' => Carbon::now()->toIso8601String(),
        ];

        $diff_service->save_update_log($log);
    }

    protected function check_module_assignment(): void
    {
        $module_service = new ModuleMappingService;

        // Try to find a module for this file
        $suggested_module = $module_service->suggest_module_for_new_file($this->file_path);

        if ($suggested_module) {
            // Queue a module update for the suggested module
            UpdateModuleDocumentationJob::dispatch($suggested_module, $this->to_sha, $this->model);
        }
    }
}
