# DPL pretix

[DPL CMS] module for integration with the [pretix] ticketing platform.

Builds on ideas and experiences from <https://github.com/itk-dev/itk_pretix>.

## Installation

<https://www.drupal.org/project/dpl_pretix>

Enable the module and go to `/admin/config/dpl_pretix` to configure the module.

## Usage

The module will add a "pretix" section on all events.

pretix settings: `/admin/config/dpl_pretix`

Log messages: `/admin/reports/dblog?type[]=dpl_pretix`

## pretix

We need a _template event_ in pretix, and this template event [will be
cloned](https://docs.pretix.eu/en/latest/api/resources/events.html#post--api-v1-organizers-(organizer)-events-(event)-clone-)
to create new events in pretix.

The template event must

1. be a multiple dates event
2. have a single subevent (date)

## Mapping DPL CMS data to pretix data

DPL CMS uses the [Recurring Events](https://www.drupal.org/project/recurring_events) module to create and manage events.
Technically, a _event_ actually consists of an _event series_ entity and a number of associated _event instance_
entities. See the [Recurring Events module page](https://www.drupal.org/project/recurring_events) and documentation
pages, e.g. [Recurring Events Main
Module](https://www.drupal.org/docs/contributed-modules/recurring-events/recurring-events-main-module), for further
details and explanations.

An event instance in DPL CMS is mapped to an event in pretix, and an event instances are mapped to a dates (sub-events).
As a special case, an event series with _only one instance_ can be mapped to a singular event in pretix, i.e. the single
instance is not mapped to an object in pretix. See the [pretix User
Guide](https://docs.pretix.eu/en/latest/user/index.html#) for details on pretix and its events.

So far, so good … how this is actually done is a little (more) complicated.

### Hooks

> [!WARNING]
> Incomplete section ahead!

A number of Drupal hooks are implemented to make event and dates in pretix reflect event series and instances in DPL CMS.

First, the obvious ones:

* [`hook_entity_insert`](https://api.drupal.org/api/drupal/core%21lib%21Drupal%21Core%21Entity%21entity.api.php/function/hook_entity_insert/10)
* [`hook_entity_update`](https://api.drupal.org/api/drupal/core%21lib%21Drupal%21Core%21Entity%21entity.api.php/function/hook_entity_update/10)
* [`hook_entity_delete`](https://api.drupal.org/api/drupal/core%21lib%21Drupal%21Core%21Entity%21entity.api.php/function/hook_entity_delete/10)

And them some less obvious ones (from Recurring Events) used to make everything fall into place:

* [`hook_recurring_events_event_instances_pre_create_alter`](https://git.drupalcode.org/project/recurring_events/-/blob/2.0.x/recurring_events.api.php?ref_type=heads#L136)
* [`hook_recurring_events_save_pre_instances_deletion`](https://git.drupalcode.org/project/recurring_events/-/blob/2.0.x/recurring_events.api.php?ref_type=heads#L187)
* [`hook_recurring_events_save_post_instances_deletion`](https://git.drupalcode.org/project/recurring_events/-/blob/2.0.x/recurring_events.api.php?ref_type=heads#L201)

### Edit event

> [!WARNING]
> Incomplete section ahead!

### Delete event

> [!WARNING]
> Incomplete section ahead!

## pretix API client

As of now, we cannot `composer require` dependencies when building custom modules for [DPL CMS], and therefore we use a
slightly customized version of [`itk-dev/pretix-api-client-php`] to talk to [pretix].

Two of [the dependencies of
`itk-dev/pretix-api-client-php`](https://github.com/itk-dev/pretix-api-client-php/blob/develop/composer.json)
(`doctrine/collections` and `guzzlehttp/guzzle`) are (indirectly) [required by DPL
CMS](https://github.com/danskernesdigitalebibliotek/dpl-cms/blob/develop/composer.json).

The dependency on [`symfony/options-resolver`](https://symfony.com/doc/current/components/options_resolver.html) can we,
under duress, choose to live without, and [a small patch](src/Pretix/ApiClient/patches/pretix-api-client.patch) removes
use of the OptionsResolver component.

See the `build:pretix-api-client` task in `Taskfile.yml` for details on how the modified version of
`itk-dev/pretix-api-client-php` is actually built.

## Coding standards

``` shell
task dev:coding-standards:check
```

``` shell
docker run --rm --volume "$PWD:/mnt" koalaman/shellcheck:stable scripts/create-release scripts/code-analysis
```

## Code analysis

``` shell
task dev:code-analysis
```

[`itk-dev/pretix-api-client-php`]: https://github.com/itk-dev/pretix-api-client-php
[itk-dev/pretix-api-client-php[DPL CMS]: https://github.com/danskernesdigitalebibliotek/dpl-cms/
[pretix]: https://pretix.eu/about/en/ "Ticketing software that cares about your event—all the way."

``` shell
docker compose build && docker compose run --rm php scripts/create-release dev-test
```

[DPL CMS]: https://github.com/danskernesdigitalebibliotek/dpl-cms/
[pretix]: https://pretix.eu/
