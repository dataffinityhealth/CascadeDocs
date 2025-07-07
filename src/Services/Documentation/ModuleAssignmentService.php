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
        $this->log_path       = base_path(config('cascadedocs.paths.tracking.module_assignment'));
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
        $tiers = config('cascadedocs.tier_directories');

        foreach ($tiers as $tier)
        {
            $output_path = config('cascadedocs.paths.output');
            $tier_path = base_path("{$output_path}{$tier}");

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
                            $possible_extensions = config('cascadedocs.file_extensions.javascript');

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
            if ($files->count() >= config('cascadedocs.limits.module_detection.min_files_for_module'))
            {
                // Enough files to potentially form a module
                $potential_modules[$directory] = [
                    'file_count'     => $files->count(),
                    'files'          => $files->toArray(),
                    'suggested_name' => $this->suggest_module_name($directory, $files),
                ];
            }
        }

        // Conceptual groupings are now handled by AI analysis
        // The AI will analyze actual code content to suggest meaningful modules
        // rather than relying on keyword matching

        return $potential_modules;
    }

    protected function find_conceptual_groups(Collection $files): Collection
    {
        // This method is intentionally left empty as conceptual grouping
        // should be determined by AI analysis of the actual code content,
        // not by hardcoded keyword matching.
        // The AI will analyze the code and suggest appropriate modules
        // based on the actual functionality and relationships it discovers.
        return collect();
    }

    protected function suggest_module_name(string $directory, Collection $files): string
    {
        // Extract meaningful parts from directory
        $parts            = explode('/', $directory);
        $meaningful_parts = [];

        foreach ($parts as $part)
        {
            if (! in_array($part, config('cascadedocs.excluded_namespace_parts')))
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

                $minLength = config('cascadedocs.limits.module_detection.min_word_length');
                $excludedWords = config('cascadedocs.exclude.words', ['php', 'js', 'vue', 'jsx']);
                if (strlen($word) > $minLength && ! in_array($word, $excludedWords))
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
        $divisor = config('cascadedocs.limits.module_detection.confidence_divisor');
        $confidence += min($module_info['file_count'] / $divisor, 0.5);

        // Files in same directory = higher confidence
        if (! isset($module_info['type']) || $module_info['type'] !== 'conceptual')
        {
            $confidence += 0.3;
        }

        // Check file name similarity
        $files         = collect($module_info['files']);
        $common_prefix = $this->find_common_prefix($files);

        $minPrefixLength = config('cascadedocs.limits.module_detection.min_common_prefix_length');
        if (strlen($common_prefix) > $minPrefixLength)
        {
            $confidence += 0.2;
        }

        $maxConfidence = config('cascadedocs.limits.module_detection.max_confidence');
        return min($confidence, $maxConfidence);
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
