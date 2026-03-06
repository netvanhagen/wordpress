# Implementation Guide - Advanced Relevance Search (Refactored)

## What Changed?

The plugin has been completely refactored from procedural code into a professional OOP architecture:

### Before (Procedural)
```php
// All functions in includes files
function ars_perform_search( $search_term ) { ... }
function ars_index_single_post( $post_id ) { ... }
require_once 'includes/search-engine.php';
require_once 'includes/indexer.php';
// Many global functions scattered across files
```

### After (OOP)
```php
// Clear class structure with single responsibility
class ARS\Search\Engine {
    public static function search( $search_term ) { ... }
}
class ARS\Search\Indexer {
    public static function index_post( $post_id ) { ... }
}
// Everything organized, easy to understand & maintain
```

## How to Use

### 1. Basic Search (Frontend Development)
```php
use ARS\Search\Engine;

// Perform a search
$post_ids = Engine::search( 'zoekterm' );

// Result is array of post IDs ranked by relevance
foreach ( $post_ids as $post_id ) {
    echo get_the_title( $post_id );
}
```

### 2. Index a Post (Programmatically)
```php
use ARS\Search\Indexer;

// Index a single post when it changes
Indexer::index_post( $post_id );

// Remove from index
Indexer::remove_post( $post_id );

// Reindex everything
Indexer::full_reindex();
```

### 3. Get Configuration
```php
use ARS\Core\Settings;

// Get all settings
$all_settings = Settings::get_all();

// Get specific setting
$per_page = Settings::get( 'results_per_page', 10 );

// Get structured settings
$weights = Settings::get_weights();  // Array of all weight values
$synonyms = Settings::get_synonyms( 'en' );  // Synonyms for language
```

### 4. Direct Database Queries
```php
use ARS\Core\Database;

// Search the index directly
$post_ids = Database::search_index( ['word1', 'word2'], 'en' );

// Add to index
Database::insert_index( [
    'post_id' => 123,
    'title' => 'My Post',
    'content_tokens' => '...',
    // ... other fields
] );

// Log a search
Database::log_search( 'search term', 5, [123, 456, 789], 'en' );

// Get statistics
$stats = Database::get_index_stats();
```

### 5. Work with Languages (WPML)
```php
use ARS\Core\Helpers;

// Get current language
$lang = Helpers::get_current_lang();  // Returns 'en', 'nl', 'de', etc.

// Get post's language
$post_lang = Helpers::get_post_language( $post_id );

// Normalize text for consistency
$normalized = Helpers::normalize_search_term( 'téxt' );  // Returns 'text'
```

## File Organization

### Must-Have Files
- `advanced-relevance-search.php` - Main plugin file
- `uninstall.php` - Cleanup when plugin is deleted
- `classes/*` - All the classes

### Optional Files
- `includes/*` - Old procedural code (deprecated, kept for reference)
- `assets/` - Admin JavaScript
- `ARCHITECTURE.md` - Technical documentation
- `IMPLEMENTATION_GUIDE.md` - This file

## Adding Features

### Add a New Search Filter

1. **Extend the Indexer** to extract new data:
```php
// In classes/Search/Indexer.php, modify index_post()
Database::insert_index( array(
    '...',
    'my_custom_field' => $my_value,
) );
```

2. **Update Database schema** in Database::create_tables():
```php
$sql_index = "CREATE TABLE ... 
    my_custom_field text,
    ...";
```

3. **Update scoring** in Database::search_index():
```php
$score_parts[] = "CASE WHEN my_custom_field LIKE %s THEN 15 ELSE 0 END";
```

### Add a New Admin Page

1. **Create a method** in Admin\Menu:
```php
public static function render_my_page() {
    // Render HTML
}
```

2. **Register the page**:
```php
add_submenu_page(
    'ars-settings',
    'My Page Title',
    'My Page',
    'manage_options',
    'ars-my-page',
    array( __CLASS__, 'render_my_page' )
);
```

### Add a Frontend Feature

1. **Create method** in Frontend\UI:
```php
public static function my_feature() {
    // Add shortcode, filter, or action
}
```

2. **Register** in Frontend\UI::register_frontend()

## Testing

### Manual Testing Checklist

- [ ] Plugin activates without errors
- [ ] Settings page loads
- [ ] Can index posts
- [ ] Search results appear on frontend
- [ ] Highlighting works
- [ ] Pinned results work
- [ ] Logging is recorded
- [ ] Admin pages work

### Creating Tests

```php
// Test search function
$results = \ARS\Search\Engine::search( 'test' );
assertTrue( is_array( $results ) );

// Test settings
$setting = \ARS\Core\Settings::get( 'highlight_enabled' );
assertTrue( is_bool( $setting ) );

// Test database
\ARS\Core\Database::insert_index( [ ... ] );
$entry = \ARS\Core\Database::get_index_entry( $post_id );
assertNotNull( $entry );
```

## Performance Tips

1. **Batch Operations**: Use `Indexer::full_reindex()` instead of `index_post()` repeatedly
2. **Settings Caching**: Settings are automatically cached by WordPress
3. **Database Indexes**: The plugin creates indexes on common lookup columns
4. **Limit Results**: Search results are limited to 200 by default (can be increased in Engine class)

## Backward Compatibility

Old procedural functions are **NOT** automatically available. If you had code using:
```php
ars_perform_search( $term );  // OLD - No longer available
```

Use the new class-based API:
```php
\ARS\Search\Engine::search( $term );  // NEW
```

You can create wrapper functions if needed:
```php
// Add to main plugin file or a custom file
function ars_perform_search( $term ) {
    return \ARS\Search\Engine::search( $term );
}
```

## Debugging

### Enable WordPress Debug Mode

In `wp-config.php`:
```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```

Check `/wp-content/debug.log` for errors.

### Check what's indexed

```php
$stats = \ARS\Core\Database::get_index_stats();
echo 'Total indexed: ' . $stats['total'];
```

### Test search directly

```php
$results = \ARS\Search\Engine::search( 'your search term' );
echo 'Found ' . count( $results ) . ' results';
print_r( $results );  // See post IDs
```

## Need Help?

- Check `ARCHITECTURE.md` for class details
- Review the class files in `classes/` directory
- Check the old `includes/` files for reference
- Look at admin pages to see how classes are used

## What's Next?

Possible improvements:
- [ ] Add caching layer (Redis/Memcached)
- [ ] Create REST API endpoints
- [ ] Add advanced query builder
- [ ] Performance monitoring dashboard
- [ ] Unit/Integration tests
- [ ] Custom field support
- [ ] Full-text search integration
