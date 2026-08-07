# Getting Started

## Installation

Install the framework in your plugin with Composer:

```bash
composer require saltus/framework
```

## Quick Start

Once the framework is installed and Composer's autoloader is loaded by your plugin, initialize it:

```php
$autoload = __DIR__ . '/vendor/autoload.php';
if ( is_readable( $autoload ) ) {
  require_once $autoload;
}

if ( class_exists( \Saltus\WP\Framework\Core::class ) ) {
  $framework = new \Saltus\WP\Framework\Core( dirname( __FILE__ ), __FILE__ );
  $framework->register();
}
```

The framework searches for model files in `src/models/` by default (configurable via the `saltus/framework/models/path` filter).

## Model Files

A model file returns an array (or multidimensional array) of model definitions:

```php
<?php
return [
  'type' => 'cpt',
  'name' => 'movie',
];
```

Multiple models in one file:

```php
<?php
return [
  [
    'type' => 'cpt',
    'name' => 'movie',
  ],
  [
    'type'         => 'category',
    'name'         => 'genre',
    'associations' => ['movie'],
  ],
];
```

## Next Steps

- Explore the [Features Guide](/guides/features) for all configuration options
- Read the [Architecture Guide](/guides/architecture) for framework internals
- Check the [MCP/Abilities](/mcp/index) docs for AI integration
- Browse the [API Reference](/api/index) for class and interface details
