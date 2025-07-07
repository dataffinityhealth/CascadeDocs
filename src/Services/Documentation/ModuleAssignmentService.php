<?php

namespace Lumiio\CascadeDocs\Services\Documentation;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ModuleAssignmentService
{
    protected ModuleMappingService $module_service;
    protected string $log_path;

    public function __construct()
    {
        $this->module_service = new ModuleMappingService();
        $this->log_path       = base_path('docs/module-assignment-log.json');
    }

    public function analyze_module_assignments(): array
    {
        $all_documented_files = $this->get_all_documented_files();

        // Load existing do_not_document files
        $existing_log          = $this->load_log();
        $do_not_document_files = $existing_log['do_not_document'] ?? [];

        // Filter out files that are in do_not_document
        $filtered_files = $all_documented_files->filter(function ($file) use ($do_not_document_files)
        {
            return ! in_array($file, $do_not_document_files);
        });

        $module_assignments   = $this->analyze_current_assignments($filtered_files);

        // Always regenerate potential modules fresh - don't keep old suggestions
        $potential_modules    = $this->identify_potential_modules($module_assignments['unassigned']);

        $log = [
            'last_analysis'      => Carbon::now()->toIso8601String(),
            'assigned_files'     => $module_assignments['assigned'],
            'unassigned_files'   => $module_assignments['unassigned']->values()->toArray(),
            'do_not_document'    => $do_not_document_files,
            'potential_modules'  => $potential_modules,
            'module_suggestions' => $this->generate_module_suggestions($potential_modules),
        ];

        $this->save_log($log);

        return $log;
    }

    protected function get_all_documented_files(): Collection
    {
        $documented_files = collect();

        // Check all three tiers for documented files
        $tiers = ['short', 'medium', 'full'];

        foreach ($tiers as $tier)
        {
            $tier_path = base_path("docs/source_documents/{$tier}");

            if (File::exists($tier_path))
            {
                $files = File::allFiles($tier_path);

                foreach ($files as $file)
                {
                    if ($file->getExtension() === 'md')
                    {
                        // Convert documentation path back to source file path
                        $relative_doc_path = Str::after($file->getPathname(), $tier_path . DIRECTORY_SEPARATOR);
                        $source_path       = Str::beforeLast($relative_doc_path, '.md');

                        // Add appropriate extension based on path
                        if (Str::startsWith($source_path, 'app/'))
                        {
                            $source_path .= '.php';
                        } elseif (Str::startsWith($source_path, 'resources/js/'))
                        {
                            // Could be .js, .vue, .jsx, etc. - check which exists
                            $possible_extensions = ['js', 'vue', 'jsx', 'ts', 'tsx'];

                            foreach ($possible_extensions as $ext)
                            {
                                if (File::exists(base_path($source_path . '.' . $ext)))
                                {
                                    $source_path .= '.' . $ext;

                                    break;
                                }
                            }
                        }

                        $documented_files->push($source_path);
                    }
                }
            }
        }

        return $documented_files->unique();
    }

    protected function analyze_current_assignments(Collection $files): array
    {
        $assigned   = [];
        $unassigned = collect();

        foreach ($files as $file)
        {
            $module = $this->module_service->get_module_for_file(base_path($file));

            if ($module)
            {
                if (! isset($assigned[$module]))
                {
                    $assigned[$module] = [];
                }
                $assigned[$module][] = $file;
            } else
            {
                $unassigned->push($file);
            }
        }

        return [
            'assigned'   => $assigned,
            'unassigned' => $unassigned,
        ];
    }

    protected function identify_potential_modules(Collection $unassigned_files): array
    {
        $potential_modules = [];

        // Group files by directory
        $by_directory = $unassigned_files->groupBy(function ($file)
        {
            return dirname($file);
        });

        foreach ($by_directory as $directory => $files)
        {
            if ($files->count() >= 3)
            {
                // Enough files to potentially form a module
                $potential_modules[$directory] = [
                    'file_count'     => $files->count(),
                    'files'          => $files->toArray(),
                    'suggested_name' => $this->suggest_module_name($directory, $files),
                ];
            }
        }

        // Also look for conceptual groupings based on file names
        $conceptual_groups = $this->find_conceptual_groups($unassigned_files);

        foreach ($conceptual_groups as $concept => $files)
        {
            if ($files->count() >= 3)
            {
                $potential_modules["concept_{$concept}"] = [
                    'file_count'     => $files->count(),
                    'files'          => $files->toArray(),
                    'suggested_name' => Str::slug($concept),
                    'type'           => 'conceptual',
                ];
            }
        }

        return $potential_modules;
    }

    protected function find_conceptual_groups(Collection $files): Collection
    {
        $groups = collect();

        // Common concepts to look for
        $concepts = [
            'authentication' => ['auth', 'login', 'logout', 'session', 'token'],
            'authorization'  => ['permission', 'role', 'policy', 'gate', 'ability'],
            'billing'        => ['payment', 'invoice', 'subscription', 'charge', 'stripe'],
            'notification'   => ['notify', 'alert', 'email', 'sms', 'push'],
            'documentation'  => ['document', 'doc', 'generate', 'update'],
            'reporting'      => ['report', 'analytics', 'metrics', 'statistics'],
            'integration'    => ['api', 'webhook', 'external', 'third-party'],
            'testing'        => ['test', 'mock', 'stub', 'fixture'],
            'caching'        => ['cache', 'redis', 'memcached'],
            'search'         => ['search', 'filter', 'query', 'elastic'],
        ];

        foreach ($concepts as $concept => $keywords)
        {
            $matching_files = $files->filter(function ($file) use ($keywords)
            {
                $lower_file = strtolower($file);

                foreach ($keywords as $keyword)
                {
                    if (Str::contains($lower_file, $keyword))
                    {
                        return true;
                    }
                }

                return false;
            });

            if ($matching_files->isNotEmpty())
            {
                $groups[$concept] = $matching_files;
            }
        }

        return $groups;
    }

    protected function suggest_module_name(string $directory, Collection $files): string
    {
        // Extract meaningful parts from directory
        $parts            = explode('/', $directory);
        $meaningful_parts = [];

        foreach ($parts as $part)
        {
            if (! in_array($part, ['app', 'resources', 'js', 'src']))
            {
                $meaningful_parts[] = $part;
            }
        }

        if (empty($meaningful_parts))
        {
            // Fallback to analyzing file names
            $common_words = $this->find_common_words_in_files($files);

            if (! empty($common_words))
            {
                return Str::slug(implode('-', array_slice($common_words, 0, 2)));
            }
        }

        return Str::slug(implode('-', $meaningful_parts));
    }

    protected function find_common_words_in_files(Collection $files): array
    {
        $word_counts = [];

        foreach ($files as $file)
        {
            $basename = basename($file, '.' . pathinfo($file, PATHINFO_EXTENSION));
            $words    = preg_split('/(?=[A-Z])|_|-/', $basename);

            foreach ($words as $word)
            {
                $word = strtolower(trim($word));

                if (strlen($word) > 2 && ! in_array($word, ['php', 'js', 'vue', 'jsx']))
                {
                    $word_counts[$word] = ($word_counts[$word] ?? 0) + 1;
                }
            }
        }

        arsort($word_counts);

        return array_keys($word_counts);
    }

    protected function generate_module_suggestions(array $potential_modules): array
    {
        $suggestions = [];

        foreach ($potential_modules as $key => $module_info)
        {
            $suggestions[] = [
                'suggested_name' => $module_info['suggested_name'],
                'file_count'     => $module_info['file_count'],
                'confidence'     => $this->calculate_module_confidence($module_info),
                'reason'         => $this->get_suggestion_reason($key, $module_info),
            ];
        }

        // Sort by confidence
        usort($suggestions, function ($a, $b)
        {
            return $b['confidence'] <=> $a['confidence'];
        });

        return $suggestions;
    }

    protected function calculate_module_confidence(array $module_info): float
    {
        $confidence = 0.0;

        // More files = higher confidence
        $confidence += min($module_info['file_count'] / 10, 0.5);

        // Files in same directory = higher confidence
        if (! isset($module_info['type']) || $module_info['type'] !== 'conceptual')
        {
            $confidence += 0.3;
        }

        // Check file name similarity
        $files         = collect($module_info['files']);
        $common_prefix = $this->find_common_prefix($files);

        if (strlen($common_prefix) > 3)
        {
            $confidence += 0.2;
        }

        return min($confidence, 1.0);
    }

    protected function find_common_prefix(Collection $files): string
    {
        if ($files->isEmpty())
        {
            return '';
        }

        $basenames = $files->map(function ($file)
        {
            return basename($file);
        })->toArray();

        $prefix = array_shift($basenames);

        foreach ($basenames as $basename)
        {
            while (! Str::startsWith($basename, $prefix) && $prefix !== '')
            {
                $prefix = substr($prefix, 0, -1);
            }
        }

        return $prefix;
    }

    protected function get_suggestion_reason(string $key, array $module_info): string
    {
        if (isset($module_info['type']) && $module_info['type'] === 'conceptual')
        {
            return 'Files share common concept: ' . str_replace('concept_', '', $key);
        }

        return "Files located in same directory: {$key}";
    }

    public function load_log(): array
    {
        if (! File::exists($this->log_path))
        {
            return [
                'last_analysis'      => null,
                'assigned_files'     => [],
                'unassigned_files'   => [],
                'do_not_document'    => [],
                'potential_modules'  => [],
                'module_suggestions' => [],
            ];
        }

        return json_decode(File::get($this->log_path), true);
    }

    protected function save_log(array $log): void
    {
        File::put($this->log_path, json_encode($log, JSON_PRETTY_PRINT));
    }

    public function get_unassigned_files_report(): array
    {
        $log = $this->load_log();

        return [
            'total_unassigned' => count($log['unassigned_files']),
            'by_directory'     => collect($log['unassigned_files'])->groupBy(function ($file)
            {
                return dirname($file);
            })->map->count()->toArray(),
            'suggestions' => $log['module_suggestions'],
        ];
    }
}
