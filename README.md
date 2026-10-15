# Newsletter

An addon for [Modulento](https://github.com/alex01at/modulento) that adds a
double opt-in mailing list and a way for an administrator to send a message
to everyone on it, over the core's own mail transport - no outside mailing
service is needed. As an addon, it can be active together with any other
addon and with the installation's one app.

- **`/newsletter`**: a public signup form. Asking to be added sends a
  confirmation link; nothing is added to the list until that link is used.
  An address that is already confirmed, or already waiting for one, sees
  the same message either way - the form never reveals who is on the list.
- Every mail (the confirmation, and every campaign) carries a one-click
  unsubscribe link, which removes the address outright.
- **Administration → Newsletter** (permission
  `newsletter.subscribers.manage`): the number of confirmed subscribers, a
  form to send a subject and a plain-text message to all of them, and a
  history of what was sent.
- A daily task removes a signup that was never confirmed after seven days.

## Installing

Clone or symlink this repository into `extensions/newsletter` of a
Modulento installation, then enable it under Administration → Extensions.

## Running its tests

```
MODULENTO_CORE=/path/to/modulento php tests/run.php
```
