# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

CascadeDocs is a Laravel package that provides AI-powered documentation generation with multi-tier support (micro, standard, expansive). It integrates with OpenAI and Claude/Anthropic for intelligent module organization and documentation creation.

## Key Commands

### Development
```bash
# Install dependencies
composer install

# Run tests
composer test                 # Run all tests
composer test-coverage        # Run tests with coverage report
vendor/bin/pest --filter TestName  # Run specific test

# Code quality
composer format               # Format code using Laravel Pint
composer analyse             # Run PHPStan static analysis
```

### Documentation Generation Commands
```bash
# Generate documentation tiers
php artisan cascadedocs:generate-ai-documentation --tier=micro      # Quick summaries
php artisan cascadedocs:generate-ai-documentation --tier=standard   # Balanced documentation
php artisan cascadedocs:generate-ai-documentation --tier=expansive  # Comprehensive documentation

# Update documentation (Git-based incremental)
php artisan cascadedocs:update-documentation         # Update based on recent changes
php artisan cascadedocs:update-changed               # Update all changed files, modules, and architecture

# Architecture documentation
php artisan cascadedocs:generate-architecture-docs   # Generate system architecture docs

# Module management
php artisan cascadedocs:module-status                # Show current module assignments
php artisan cascadedocs:create-module                # Create a new module
php artisan cascadedocs:generate-module-docs         # Full module documentation flow
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

1. **Commands** (`src/Commands/Documentation/`)
   - `BaseDocumentationCommand`: Abstract base for all documentation commands
   - `GenerateDocumentationCommand`: Main documentation generation
   - `UpdateDocumentationCommand`: Git-based incremental updates
   - `GenerateArchitectureCommand`: Architecture documentation
   - Module-related commands for organization

2. **Services** (`src/Services/Documentation/`)
   - `DocumentationService`: Core documentation generation logic
   - `CascadeConsolidationService`: Hierarchical documentation consolidation
   - `FileAnalyzerService`: File content analysis with AI
   - `FilamentFeatureService`: Filament-specific analysis
   - `GitService`: Git integration for tracking changes
   - `ModuleService`: Module organization and assignment

3. **AI Integration**
   - Uses `shawnveltman/laravel-openai` package
   - Configurable providers: OpenAI or Claude/Anthropic
   - Model selection through config

### Documentation Tiers

1. **Micro**: Brief one-line summaries per file
2. **Standard**: Balanced documentation with key concepts
3. **Expansive**: Comprehensive documentation with examples

### Module System

- Files are organized into semantic modules (e.g., "Authentication", "User Management")
- AI analyzes codebase to suggest module assignments
- Manual overrides supported via YAML import

## Configuration

Main configuration file: `config/cascadedocs.php`

Key settings:
- `output_directory`: Where documentation is saved
- `included_extensions`: File types to document
- `excluded_patterns`: Files/directories to skip
- `module_analysis`: Enable AI-powered module assignment
- `filament_module_analysis`: Filament-specific features
- Queue configuration for async processing

## Testing Approach

- Use Pest PHP for all tests
- Feature tests in `tests/Feature/`
- Unit tests in `tests/Unit/`
- Follow existing test patterns for consistency
- Mock AI responses in tests using the established patterns

## Git Workflow

- Package follows semantic versioning
- GitHub Actions handle CI/CD
- Code style automatically fixed in CI
- PHPStan baseline exists for gradual static analysis adoption
- Current version is 0.2.x 
- Always check the existing tags before adding a new tag
- Always push the code & tags to github after you commit
