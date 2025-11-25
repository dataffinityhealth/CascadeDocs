# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

CascadeDocs is a Laravel package (PHP 8.2+, Laravel 10/11/12) that provides AI-powered documentation generation with multi-tier support (micro, standard, expansive). It integrates with OpenAI and Claude/Anthropic for intelligent module organization and documentation creation.

## Key Commands

### Development
```bash
composer install              # Install dependencies
composer test                 # Run all tests
composer test-coverage        # Run tests with coverage report
vendor/bin/pest --filter TestName  # Run specific test
composer format               # Format code using Laravel Pint
composer analyse              # Run PHPStan static analysis
```

### Documentation Generation Commands
```bash
# Class documentation (run first for new codebases)
php artisan cascadedocs:generate-class-docs          # Generate all tiers
php artisan cascadedocs:generate-class-docs --tier=standard  # Specific tier

# Tier-specific generation (alternative to generate-class-docs)
php artisan cascadedocs:generate-ai-documentation --tier=micro      # Quick summaries
php artisan cascadedocs:generate-ai-documentation --tier=standard   # Balanced documentation
php artisan cascadedocs:generate-ai-documentation --tier=expansive  # Comprehensive documentation

# Update documentation (Git-based incremental)
php artisan cascadedocs:update-changed               # Update all changed files, modules, and architecture
php artisan cascadedocs:update-documentation         # Update based on recent changes
php artisan cascadedocs:update-after-merge           # Update after git merge

# Architecture documentation
php artisan cascadedocs:generate-architecture-docs   # Generate system architecture docs

# Module management
php artisan cascadedocs:module-status                # Show current module assignments
php artisan cascadedocs:create-module                # Create a new module
php artisan cascadedocs:generate-module-docs         # Full module documentation flow
php artisan cascadedocs:assign-files-to-modules      # Assign unassigned files to modules
php artisan cascadedocs:sync-module-assignments      # Sync module assignments from metadata
php artisan cascadedocs:generate-module-index        # Regenerate modules/index.md
php artisan cascadedocs:analyze-modules --suggest    # View detailed module analysis
```

### Module Documentation Flow

The `cascadedocs:generate-module-docs` command orchestrates the entire module documentation process:
1. Analyzes module assignments using AI (creates initial module structure) - **SKIPPED if module-assignment-log.json already exists**
2. Assigns unassigned files to modules (uses existing analysis, doesn't duplicate)
3. Syncs module assignments
4. Updates all module documentation (with --force to skip confirmation)
5. Shows final module status

**Important Notes**:
- The flow is optimized to avoid duplicate AI analysis calls
- Step 1 is automatically skipped if `module-assignment-log.json` exists, even if there are unassigned files
- Use `--fresh` option to force a new analysis: `php artisan cascadedocs:generate-module-docs --fresh`
- This allows you to run the documentation generation multiple times without re-analyzing the entire codebase
- For updating module assignments after code changes, use the update commands instead

## Architecture

### Core Components

1. **Commands** (`src/Commands/Documentation/`) - 15+ artisan commands for documentation generation
2. **Services** (`src/Services/Documentation/`) - Core business logic:
   - `DocumentationService`: Core documentation generation
   - `CascadeConsolidationService`: Hierarchical documentation consolidation
   - `FileAnalyzerService`: File content analysis with AI
   - `GitService`: Git integration for tracking changes
   - `ModuleService`: Module organization and assignment
3. **Jobs** (`src/Jobs/`) - Queue jobs for async processing
4. **Support** (`src/Support/`) - Helper classes and utilities
5. **AI Integration** - Uses `shawnveltman/laravel-openai` package (OpenAI or Claude/Anthropic)

### Documentation Tiers

1. **Micro**: Brief one-line summaries per file
2. **Standard**: Balanced documentation with key concepts
3. **Expansive**: Comprehensive documentation with examples

### Module System

- Files are organized into semantic modules (e.g., "Authentication", "User Management")
- AI analyzes codebase to suggest module assignments
- Manual overrides supported via YAML import

### Generated Documentation Structure
```
docs/source_documents/
├── short/           # Micro-tier documentation
├── medium/          # Standard-tier documentation
├── full/            # Expansive-tier documentation
├── modules/         # Module documentation
│   ├── content/     # Module overview documents
│   ├── metadata/    # Module config (JSON)
│   └── index.md     # Searchable module index
└── architecture/    # System architecture docs
```

## Configuration

Main configuration file: `config/cascadedocs.php`

Key settings:
- `output_directory`: Where documentation is saved
- `included_extensions`: File types to document
- `excluded_patterns`: Files/directories to skip
- `module_analysis`: Enable AI-powered module assignment
- `filament_module_analysis`: Filament-specific features
- Queue configuration for async processing

## Testing

- Use Pest PHP for all tests (`tests/Feature/` and `tests/Unit/`)
- Mock AI responses in tests using established patterns

## Git Workflow

- Package follows semantic versioning
- GitHub Actions handle CI/CD
- Code style automatically fixed in CI
- PHPStan baseline exists for gradual static analysis adoption
- Current version is 0.2.x 
- Always check the existing tags before adding a new tag
- Always push the code & tags to github after you commit
