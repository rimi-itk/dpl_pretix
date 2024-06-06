# DPL pretix

[DPL CMS] module for integration with the [pretix] ticketing platform.

## Installation

Enable the module and go to `/admin/config/dpl_pretix` to configure the module.

## Usage

The module will add a "pretix" section on all events.

## pretix API client

Due to the (limited) way installing custom modules in DPL CMS works, we use a customized version of
[itk-dev/pretix-api-client-php] to talk to [pretix].

## Coding standards

``` shell
task dev:coding-standards:check
```

## Code analysis

``` shell
task dev:code-analysis
```

[itk-dev/pretix-api-client-php]: https://github.com/itk-dev/pretix-api-client-php
[DPL CMS]: https://github.com/danskernesdigitalebibliotek/dpl-cms/
[pretix]: https://pretix.eu/about/en/ "Ticketing software that cares about your event—all the way."
