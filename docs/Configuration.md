# Configuration

Go to Configuration » Web services » pretix (`/admin/config/dpl_pretix`) to configure the DPL pretix module.

## pretix (prod)

**Domain**: Enter the [domain name](https://en.wikipedia.org/wiki/Domain_name) of your production dpl cms site (note
that a domain does not contain any slashes (`/`))

**URL**: Enter the URL of your production dpl cms site

**Organizer**: The organizer short form used to connect to pretix

**The API token of the Organizer Team**: The API token of a team in the pretix organizer

**Event slug template**: Template used to generate evet short forms in pretix. Use a placeholder to generate unique
values in pretix, e.g. `dpl-cms-{id)`.

**Default language code**: The default language code used on pretix events.

**Template events used to create new events in pretix**: The template pretix events used to create new pretix events, e.g.

``` yaml
egratis: Skabelonenkelt gratis (egratis)
ebetal: Skabelonenkelt betaling (ebetal)
sgratis: Skabelonserie gratis (sgratis)
sbetal: Skabelonserie betal (sbetal)
```

the value before `:`, e.g. `egratis`, must be the short form of the template event in pretix, and the value after `:`,
e.g. `Skabelonenkelt gratis (egratis)`, is shown when selecting a template event for a dpl cms event:

**pretix webhook URL**: not (yet) used

## pretix (test)

The same as "[pretix (prod)](#pretix-prod)", but for your test site.

## PSP elements

**pretix PSP property name**: Enter the "organizer metadata property for the PSP element in pretix" (probably: PSP)

**Available PSP elements**: Add elements with name and valuefor each PSP element that should be available for selection
when creating events in pretix, e.g.

1.
    Name: Library south
    Value: XG-1234567890-00001

1.
    Name: Library north
    Value: XG-1234567890-00002

## pretix event node defaults

**Default event capacity**: Enter the default event capacity, i.e. number of tickets available

**Create and update in pretix**: The default value of the "Create and update in pretix" settings on a dpl cms event form

**Default ticket category name**: The default name for the single ticket category needed to set the ticket price in
pretix

## Event form

**Location of pretix section**: Define where to show the pretix section on the dpl cms event form.
