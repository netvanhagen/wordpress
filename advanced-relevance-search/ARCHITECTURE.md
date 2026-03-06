# Advanced Relevance Search - OOP Refactored

## Overview

This plugin has been completely refactored into a professional OOP architecture with proper namespacing and separation of concerns.

## Directory Structure

```
advanced-relevance-search/
├── classes/
│   ├── Plugin.php              # Main plugin coordinator
│   ├── Hooks.php               # WordPress hook registrations
│   │
│   ├── Core/
│   │   ├── Helpers.php         # Utility functions (normalize, language support)
│   │   ├── Settings.php        # Settings management & admin fields
│   │   └── Database.php        # All database operations
│   │
│   ├── Search/
│   │   ├── Engine.php          # Search algorithm & ranking
│   │   ├── Indexer.php         # Post indexing & batch processing
│   │   └── QueryInterceptor.php # WordPress query hooks
│   │
│   ├── Admin/
│   │   ├── Menu.php            # Admin pages & menus
│   │   ├── MetaBox.php         # Post metaboxes
│   │   └── AjaxHandler.php     # AJAX endpoints
│   │
│   └── Frontend/
│       └── UI.php              # Frontend highlighting & filters
│
├── includes/                   # Legacy files (deprecated)
├── advanced-relevance-search kopie.php  # Main plugin file
├── uninstall.php              # Plugin uninstaller
└── ...other files
```

## Namespace Structure

All classes use the `ARS\` namespace:

- `ARS\Plugin`                  - Main initializer
- `ARS\Hooks`                   - Hook registration
- `ARS\Core\Helpers`            - Utilities
- `ARS\Core\Settings`           - Configuration
- `ARS\Core\Database`           - DB operations
- `ARS\Search\Engine`           - Search algorithm
- `ARS\Search\Indexer`          - Indexing logic
- `ARS\Search\QueryInterceptor` - Query hooks
- `ARS\Admin\Menu`              - Admin interface
- `ARS\Admin\MetaBox`           - Post meta
- `ARS\Admin\AjaxHandler`       - AJAX handlers
- `ARS\Frontend\UI`             - Frontend features

## Architecture Principles

### 1. Single Responsibility Principle
Each class has one clear responsibility:
- **Helpers**: Text processing & utilities
- **Settings**: Configuration management
- **Database**: All DB queries
- **Engine**: Search algorithm only
- **Indexer**: Indexing logic only
- **QueryInterceptor**: Query interception only

### 2. Separation of Concerns
- Admin code is completely separate from frontend
- Search logic is separate from indexing
- Database access is centralized in Database class
- Settings are managed through Settings class

### 3. Dependency Injection Ready
Classes use static methods for WordPress integration, but can be extended with DI pattern.

## Key Classes

### Core\Helpers
```php
Helpers::get_current_lang()               // Get WPML language
Helpers::normalize_search_term()          // Normalize text
Helpers::extract_search_words()           // Parse search query
Helpers::should_index_post()              // Validate post
```

###Core\Settings
```php
Settings::get_all()                       // Get all settings
Settings::get($key, $default)             // Get single setting
Settings::get_post_types()                // Get indexed post types
Settings::get_weights()
```

### Core\Database
```php
Database::search_index($words, $lang)     // Search with ranking
Database::insert_index($data)             // Add to index
Database::get_pinned_results($term)       // Get pinned results
Database::log_search($term, count, ids)   // Log search
```

### Search\Engine
```php
Engine::search($search_term)              // Perform search (returns post IDs)
// Internally handles synonyms & pinning
```

### Search\Indexer
```php
Indexer::index_post($post_id)             // Index single post
Indexer::index_batch($offset, $size)      // Batch indexing
Indexer::full_reindex()                   // Full reindex
```

### Search\QueryInterceptor
Registers hooks to intercept WordPress search and replace with custom search.

### Admin\*
Handle all admin dashboard features:
- Settings page
- Pinned results management
- Search logging & analytics

### Frontend\UI
Frontend features:
- Search result highlighting
- Category filters shortcode

## Adding New Features

### Add a new search weighting factor:

1. **Update Settings class** - Add field registration
```php
add_settings_field( 'my_factor', 'Label', ... );
Settings::get( 'my_factor', 5 );
```

2. **Update Database class** - Add to scoring SQL
```php
$weights['my_factor'] = Settings::get( 'weight_my_factor', 5 );
```

3. **Update search index** - Add field to DB schema
```php
$sql_index = "CREATE TABLE ... my_factor_tokens text ...";
```

4. **Update Indexer class** - Extract and store data
```php
$data['my_factor_tokens'] = '';  // Extract & populate
```

### Add a new admin page:

1. **Create handler method** in Admin\Menu
2. **Register with add_submenu_page()** 
3. **Render HTML & handle forms**
4. **Use Database:: methods** for data access

## Legacy Support

The old `includes/` files are still present for reference but NOT included by default. The plugin is 100% class-based now.

To migrate old code that used functions like `ars_perform_search()`:
```php
// Old way
$results = ars_perform_search( $term );

// New way
$results = \ARS\Search\Engine::search( $term );
```

## Performance Considerations

1. **Database queries are optimized** - All searches use proper LIMIT and KEY indices
2. **Batch processing** - Indexing uses 50-post batches
3. **Settings are cached** - WordPress will cache `ars_settings` option
4. **Post__in ordering** - Uses FIELD() for custom ranking

## Maintaining & Extending

The structure makes it easy to:
- Add new search algorithms (create new Engine methods)
- Add new admin pages (create new Menu methods)
- Add new front-end features (create new UI methods)
- Add new indexing rules (extend Indexer class)

All data changes are funneled through the Database class, making it easy to swap to a different storage later if needed.

## Version History

- **1.2.0** - OOP refactoring & clean architecture
- **1.1.10** - Previous version (procedural)
