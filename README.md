# ☕ magicdiff ☕

magicdiff generates automagically sql diffs for a database between two given states.

## Key benefits

* Spits out real sql queries that can be applied on previous state
* Does not use sql triggers or binary / ddl logs
* Works with any shared hosting provider
* Data and schema changes, at the same time
* Fast
* Command line tool and class usage possible
* Covered through test suite
* Returns (separate) diffs and patch files
* Can ignore certain tables 
* Takes care of (primary) keys and all kinds or altering tables
* Zero dependencies

## Disclaimer

This does not prevent you from taking backups. Use this script at your own risk.

## Command line

### Installation

```
wget https://raw.githubusercontent.com/vielhuber/magicdiff/master/src/magicdiff.php
```

### Usage

```
php magicdiff.php setup
php magicdiff.php init
php magicdiff.php diff
```


## Class

### Installation

```
composer require vielhuber/magicdiff
```
    
### Usage

```php
<?php
require __DIR__ . '/vendor/autoload.php';
use vielhuber\magicdiff\magicdiff;
magicdiff::setup();
magicdiff::init();
magicdiff::diff();
```

