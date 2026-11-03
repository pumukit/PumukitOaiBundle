# OaiBundle

Bundle for the [PuMuKIT Video Platform 6.0](https://github.com/pumukit/PuMuKIT/tree/6.0.x) or higher. It publishes the
public videos of the portal as an [OAI-PMH 2.0](https://www.openarchives.org/OAI/openarchivesprotocol.html) repository
with Dublin Core (`oai_dc`) metadata, so that harvesters such as Recolecta, Hispana, Europeana or BASE can collect them.

## Installation

```bash
composer require teltek/pumukit-oai-bundle
```

If Symfony Flex does not do it, add the bundle to `config/bundles.php`:

```php
Pumukit\OaiBundle\PumukitOaiBundle::class => ['all' => true],
```

Import its routes, for example in `config/routes/pumukit_oai.yaml`:

```yaml
pumukit_oai:
    resource: "@PumukitOaiBundle/Resources/config/routing.yml"
    prefix:   /
```

Create the index on `updatedAt`, which the harvests by date use:

```bash
php bin/console doctrine:mongodb:schema:update
```

## Repository

The base URL is `/oai.xml`, with `GET` or `POST`, and it supports the six verbs: `Identify`, `ListMetadataFormats`,
`ListSets`, `ListIdentifiers`, `ListRecords` and `GetRecord`. The only metadata format is `oai_dc`.

A record is a video that is published (if `list_only_published_objects`), has the `pub_channel_tag` channel, has a
public broadcast and has a visible track, document, image or external media with the `display_track_tag` tag, or is an
Opencast video. Its identifier is the id of the video, and its set is its series. `ListSets` lists the series that are
not hidden, and `set=_all_` is the same as no set.

The datestamp of a record is the last modification of the video (`updatedAt`), or its publication date for the old
videos without it. `from` and `until` filter by that date, with day (`2026-01-31`) or second
(`2026-01-31T10:00:00Z`) granularity, in UTC. The repository does not keep deleted records: a video that stops meeting
the rules leaves the lists.

The lists are paged by `page_size`. The resumption token holds the position, the filters and the size of the list,
and it does not expire, so each page costs the same whatever its position.

Wrong requests get the OAI-PMH error: `badVerb`, `badArgument`, `badResumptionToken`, `cannotDisseminateFormat`,
`idDoesNotExist` or `noRecordsMatch`.

## Dublin Core

| Element | Value |
|---------|-------|
| `dc:title` | Title, in the default locale |
| `dc:creator` | People with the `role_for_dc_creator` role |
| `dc:subject` | Tags, in the `dc_subject_format` format |
| `dc:description` | Description, in the default locale |
| `dc:publisher` | Copyright, if `use_copyright_as_dc_publisher` |
| `dc:date` | Publication date |
| `dc:type` | `video_dc_type`, `audio_dc_type`, `image_dc_type` or `doc_dc_type`, from the type of the video |
| `dc:format` | MIME types of the visible media, from their extension |
| `dc:identifier` | URLs of `dc_identifier_url_mapping` |
| `dc:language` | Languages of the visible tracks, in ISO 639-1 and ISO 639-2 |
| `dc:rights` | License if `use_license_as_dc_rights`, else copyright |
| `dc:thumbnail` | First picture, if `use_dc_thumbnail`. It is not a Dublin Core element, so the harvesters that validate the schema reject it |

The empty elements are left out, and the texts are cleaned of characters that are not valid in XML.

## Configuration

```yaml
pumukit_oai:
    enable: true
    list_only_published_objects: true
    pub_channel_tag: PUCHWEBTV
    display_track_tag: display
    page_size: 100
    use_dc_thumbnail: true
    video_dc_type: 'Moving Image'
    audio_dc_type: Sound
    doc_dc_type: Text
    image_dc_type: Image
    role_for_dc_creator: actor
    use_license_as_dc_rights: false
    use_copyright_as_dc_publisher: false
    dc_subject_format: title
    dc_identifier_url_mapping: portal
```

| Option | Default | Description |
|--------|---------|-------------|
| `enable` | `true` | If false, `/oai.xml` gives 404 |
| `list_only_published_objects` | `true` | List only the videos in published status |
| `pub_channel_tag` | `PUCHWEBTV` | Publication channel the videos must have |
| `display_track_tag` | `display` | Tag of the visible media the videos must have |
| `page_size` | `100` | Records or sets of each page, from 1 to 1000 |
| `use_dc_thumbnail` | `true` | Add `dc:thumbnail` with the first picture |
| `video_dc_type` | `Moving Image` | `dc:type` of the video and external videos. See the [DCMI types](https://www.dublincore.org/specifications/dublin-core/dcmi-type-vocabulary/) |
| `audio_dc_type` | `Sound` | `dc:type` of the audio videos |
| `doc_dc_type` | `Text` | `dc:type` of the document videos |
| `image_dc_type` | `Image` | `dc:type` of the image videos |
| `role_for_dc_creator` | `actor` | Role code of `dc:creator`; `null` takes the people of all the roles |
| `use_license_as_dc_rights` | `false` | Use the license as `dc:rights` instead of the copyright |
| `use_copyright_as_dc_publisher` | `false` | Add the copyright as `dc:publisher` |
| `dc_subject_format` | `title` | `title` (*Mathematics*), `code` (*U120000*), `all` (*U120000 - Mathematics*) or `e-ciencia` (*12 Mathematics*) |
| `dc_identifier_url_mapping` | `portal` | `portal` (page of the video), `iframe` (player), `track` (files of the visible tracks), `portal_and_track` or `all` |

`Identify` takes the repository name from `pumukit.info.description` and the admin email from `pumukit.info.email`.

## Upgrade from 5.x

- The bundle requires PHP 8.5 and PuMuKIT 6.0. Run `doctrine:mongodb:schema:update` to create the `updatedAt` index.
- **The lists are paged.** Before, the resumption token was never sent, so the harvesters only got the first 10
  records. Now they get every record, in pages of `page_size` sorted by id, so the first harvest after the upgrade
  collects the whole repository. The tokens of 5.x are not valid.
- **Only the videos with a public broadcast are listed.** The videos with login, password or group broadcast are no
  longer in the repository.
- **The datestamp is the last modification**, in UTC with seconds, instead of the publication date, so the harvests by
  date get the changed records. `from` and `until` accept seconds and cover whole days with day granularity.
- `dc:updateAt` is removed: it is not a Dublin Core element and the header datestamp has that date now.
- `dc:type` is written once per record and also for the image and document videos; the external videos take
  `video_dc_type` instead of *External media*. `dc:format` comes from the file extension, without reading the files,
  and a record whose file is missing no longer breaks the response.
- `dc:language` comes from the language of the tracks. Before, it was always the language of the request.
- The empty `dc:publisher` and the empty elements are no longer written. `responseDate` is in UTC.
- The `frontend` filter of the bundle is removed: the repository checks the rules in its own queries.
- `POST` requests and the OAI-PMH errors for illegal, missing or repeated arguments are supported.
