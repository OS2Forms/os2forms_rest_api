# OS2Forms REST API

We use [Webform REST](https://www.drupal.org/project/webform_rest) to expose a
number of API endpoints.

## Installation

```sh
composer require os2forms/os2forms_rest_api
vendor/bin/drush pm:enable os2forms_rest_api
```

## Authentication

We use [Key auth](https://www.drupal.org/project/key_auth) for authenticating
api users.

A user can access the Webform REST API if

1. it has the “OS2Form REST API user” (`os2forms_rest_api_user`) role,
2. has been granted access to the formular
   (see [Custom access control](#custom-access-control) )
3. has a generated key (User > Edit > Key authentication; `/user/«user
   id»/key-auth`).

The “OS2Form REST API user” role gives read-only access to the API. To get write
access, a user must also have the “OS2Form REST API user (write)”
(`os2forms_rest_api_user_write`) role.

## Endpoints

| Name                | Path                                           | Methods |
|---------------------|------------------------------------------------|---------|
| Webform Elements    | `/webform_rest/{webform_id}/elements`          | GET     |
| Webform Fields      | `/webform_rest/{webform_id}/fields`            | GET     |
| Webform Submission  | `/webform_rest/{webform_id}/submission/{uuid}` | GET     |
| Webform Submissions | `/webform_rest/{webform_id}/submissions`       | GET     |
| Webform Submit      | `/webform_rest/submit`                         | POST    |
| File                | `/entity/file/{file_id}`                       | GET     |

## Examples

### Get file content from webform submission

Example uses `some_webform_id` as webform id, `some_submission_id` as submission
id and `dokumenter` as the webform file element key.

Request:

```sh
> curl --silent --header 'api-key: …' https://127.0.0.1:8000/webform_rest/some_webform_id/submission/some_submission_uuid
```

Response:

```json
{
  …,
  "data": {
    "navn": "Jack",
    "telefon": "12345678"
    "dokumenter": {
      "some_document_id",
      "some_other_docuent_id"
    }
  }
}
```

Use the file endpoint from above to get information on a file, substituting
`{file_id}` with the actual file id (`some_document_id`) from the previous
request.

Request:

```sh
> curl --silent --header 'api-key: …' https://127.0.0.1:8000/webform_rest/entity/file/some_document_id
```

Response:

```json
{
  …,
  "uri": [
    {
      "value": "private:…",
      "url": "/system/files/webform/some_webform_id/…"
    }
  ],
  …
}
```

Finally, you can get the actual file by combining the base url
with the url from above response:

```sh
> curl --silent --header 'api-key: …' http://127.0.0.1:8000/system/files/webform/some_webform_id/…
```

Response:

The actual document content.

### Submit webform

Request:

```sh
> curl --silent --location --header 'api-key: …' --header 'content-type: application/json' https://127.0.0.1:8000/webform_rest/submit --data @- <<'JSON'
{
  "webform_id": "{webform_id}",
  "//": "Webform field values (cf. /webform_rest/{webform_id}/fields)",
  "navn_": "Mikkel",
  "adresse": "Livets landevej",
  "mail_": "mikkel@example.com",
  "telefonnummer_": "12345678"
}
JSON
```

Response:

```json
{"sid":"6d95afe9-18d1-4a7d-a1bf-fd38c58c7733"}
```

(the `sid` value is a webform submission uuid).

### Webform submissions

You can filter results based on submission time by
adding query parameters to the url:

| Name        | Value                | Example      |
|-------------|----------------------|--------------|
| `starttime` | PHP DateTime formats | `yesterday`  |
| `endtime`   | PHP DateTime formats | `2023-10-23` |

If left out, filtering upon the left out parameter will not be done.

The example beneath requests all submissions after October 1. 2023.

Request:

```sh
> curl --silent --header 'api-key: …' 'https://127.0.0.1:8000/webform_rest/some_webform_id/submissions?starttime=2023-10-01'
```

Response:

```json
{"webform_id":"some_webform_id",
  "starttime":"2023-10-01",
  "submissions":{
    "123":"https:\/\/127.0.0.1:8000\/da\/webform_rest\/some_webform_id\/submission\/44b1fe1b-ee96-481e-b941-d1219d1dcb55",
    "124":"https:\/\/127.0.0.1:8000\/da\/webform_rest\/some_webform_id\/submission\/3652836d-3dab-4919-b880-e82cbbf3c24c"
  }
}
```

## Custom access control

To give access to webforms, you need to specify a list of API users that are
allowed to access a webform's data via the API.

Go to Settings > General > Third party settings > OS2Forms > REST API to specify
which users can access a webform's data.

### Technical details

The custom access check is implemented in an event subscriber listening on the
`KernelEvents::REQUEST` event. See
[EventSubscriber::onRequest](src/EventSubscriber/EventSubscriber.php) for
details.

In order to make documents accessible for api users the Key auth
`authentication_provider` service has been overwritten to be global. See
[os2forms_rest_api.services](os2forms_rest_api.services.yml).

## Linked data

To make using the REST API easier we add linked data to `GET` responses:

```json
{
  …
  "data": {
    "file": "87",
    "name": "The book",
    "linked": {
      "file": {
        "87": {
          "id": "87",
          "url": "http://os2forms.example.com/system/files/webform/os2forms/1/cover.jpg",
          "mime_type": "image/jpeg",
          "size": "96757"
        }
      }
    }
  }
}
```

## Attachments

Attachment elements are added to `GET` responses:

```json
{
  …
  "data": {
    …
    "attachments": {
      "attachment_pdf": {
        "name": "Attachment (pdf)",
        "type": "pdf",
        "url": "http://os2forms.example.com/da/webform/os2forms/submissions/42/attachment/pdf/pdf.pdf"
      },
      …
    }
  }
}
```

### Technical details on linked data and attachments

In order to add linked data, we apply a patch,
[webform_rest_submission.patch](patches/webform_rest_submission.patch), to the
Webform REST module and implement an event subscriber,
[WebformSubmissionDataEventSubscriber](src/EventSubscriber/WebformSubmissionDataEventSubscriber.php),
to add the linked data.
