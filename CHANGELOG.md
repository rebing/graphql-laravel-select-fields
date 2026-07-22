CHANGELOG
=========

[Next release](https://github.com/rebing/graphql-laravel-select-fields/compare/1.0.0...master)

### Added
- Deferred per-variant relation loading: the same Eloquent relation requested
  with different arguments (via aliases or divergent branches) now resolves
  each argument set separately, fixing
  [rebing/graphql-laravel#604](https://github.com/rebing/graphql-laravel/issues/604)
  (requires `rebing/graphql-laravel` >= 10.1; disable with
  `graphql.select_fields.deferred_variants = false`)

1.0.0
-----

- Stable release for [rebing/graphql-laravel](https://github.com/rebing/graphql-laravel) 10.0.0.

0.1.0
-----

Initial release. Extracted from [rebing/graphql-laravel](https://github.com/rebing/graphql-laravel).
