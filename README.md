# Trash

[![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](LICENSE)

Adds "soft"-delete support to CakePHP tables.

## Install

Using [Composer][composer]:

```
composer require vandalorumrex/trash
```

You then need to load the plugin. You can use the shell command:

```
bin/cake plugin load Muffin/Trash
```

## Usage

In your table(s), add the behavior like you would for any other behavior:

```php
// in the initialize() method
$this->addBehavior('Muffin/Trash.Trash');
```

By default, the behavior expects your table to have a *nullable* `DATETIME` column
named `deleted` or `trashed`. Or you could customize the name when adding the behavior:

```php
// in your table's initialize() method
$this->addBehavior('Muffin/Trash.Trash', [
    'field' => 'deleted_at'
]);
```

or, at the global level, in `bootstrap.php`:

```php
Configure::write('Muffin/Trash.field', 'deleted_at');
```

Finally, if you would like to keep the default cake behavior when running
`find()` or `delete()` operations and explicitly call the behavior when you need
'trash'-ing functionality, just disable the event(s):

```php
// in the initialize() method
$this->addBehavior('Muffin/Trash.Trash', [
    'events' => ['Model.beforeFind'] // enables the beforeFind event only, false to disable both
]);
```

or use the purge option:

```php
$table->delete($entity, ['purge' => true]);
```

## Detecting trashing
If you need to distinguish between deletion and trashing the behavior
adds the ['trash' => true ] option to the afterDelete event
it creates when trashing.

### Cascading deletion

If you'd like to have related records marked as trashed when deleting a parent
item, you can just attach the behavior to the related table classes, and set the
`'dependent' => true, 'cascadeCallbacks' => true` options in the table relationships.

This works on relationships where the item being deleted in the owning side of
the relationship. Which means that the related table should contain the foreign key.

If you don't want to cascade on trash:
```php
// in the initialize() method
$this->addBehavior('Muffin/Trash.Trash', [
    'cascadeOnTrash' => false,
]);
```

### Custom Finders

- **onlyTrashed** - helps getting only those trashed records.
- **withTrashed** - when filtering out the trashed records by default, this method comes in handy to have them included
as part of certain calls.

### Extras

- **emptyTrash()** - permanently deletes all trashed records.
- **restoreTrash($entity = null, array $options = [])** - restores one (or all) trashed records.
- **cascadingRestoreTrash($entity = null, array $options = [])** - restores one (or all) trashed records including
those of dependent associations.
- **trash($entity, array $options = [])** - like `delete()` but for a soft-delete (handy when `Model.beforeDelete` is
disabled by default).
- **trashAll(array $conditions)** - like `deleteAll()` but for soft-deletes.

## Patches & Features

* Fork
* Mod, fix
* Test - this is important, so it's not unintentionally broken
* Commit - do not mess with license, todo, version, etc. (if you do change any, bump them into commits of
their own that I can ignore when I pull)
* Pull request - bonus point for topic branches

To ensure your PRs are considered for upstream, you MUST follow the CakePHP coding standards.

## Bugs & Feedback

http://github.com/vandalorumrex/trash/issues

## License

This library is license under the MIT License (MIT). Please see License File for more information.

## Credits

This library was forked from [UseMuffin Trash's](https://github.com/UseMuffin) on Github https://github.com/UseMuffin/Trash.
