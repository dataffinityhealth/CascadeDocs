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
php artisan docs:generate micro      # Quick summaries
php artisan docs:generate standard   # Balanced documentation
php artisan docs:generate expansive  # Comprehensive documentation

# Update documentation (Git-based incremental)
php artisan docs:update              # Update based on recent changes

# Architecture documentation
php artisan docs:architecture        # Generate system architecture docs

# Module management
php artisan docs:modules             # Show current module assignments
php artisan docs:import-modules      # Import from YAML file
```

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
