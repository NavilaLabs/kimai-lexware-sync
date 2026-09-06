# License check: design specification

Status: approved, ready for an implementation plan.
Date: 2026-09-06

## 1. Purpose

The plugin is to be sold from the author's own website, with payment handled by Paddle. This
specification covers the part of that which lives inside the plugin: a check that refuses to
convert anything unless a license has been confirmed.

It covers nothing else. The service that issues licenses, the sales page, and the connection to
Paddle are a separate project with its own repository. What the two share is the protocol in
section 5, and this side of it is built against a fake.

The check is written in full and then switched off, because the service it talks to does not
exist yet. Section 10 describes that switch, which is the state this plugin ships in until the
other project is ready.

## 2. What a license check can and cannot do here

The plugin is licensed under MIT and its source is readable at every customer. Anyone can
delete the check. That is understood and accepted: the purpose is not to make removal
impossible, it is to make paying the path of least resistance, and to make the state of a
license visible to an honest customer who forgot to renew.

That framing decides several details below. It is why an unreachable service must never break a
paying customer's day, why the messages say precisely what is wrong, and why no effort goes into
obfuscation.

## 3. Where the check sits

Inside `OrderConfirmationProcessor::convert()`, `InvoiceProcessor::convert()` and
`InvoiceProcessor::confirmExisting()`, not in the controllers.

An earlier version of this section named only the two `convert()` methods and claimed they were
the only way a conversion happens. That was wrong: `InvoiceProcessor::confirmExisting()` records
a conversion for an invoice that was already created directly in Lexware, without ever calling
`convert()` and without any Lexware request of its own, so it is a third entry point rather than
a caller of one of the other two. All three methods are the only way an order confirmation
becomes a Kimai project or a set of timesheets becomes a recorded Lexware invoice. Every route
into them, the triage screen, the automatic conversion inside `OrderConfirmationSynchronizer`,
and the invoice assignment screen, passes through one of the three. A check placed in all three
cannot be bypassed by any existing caller, and a caller added later is covered without anyone
remembering to cover it.

All three throw `LicenseRequiredException`, carrying the verdict so the caller can say why. Every
caller already catches domain exceptions of this kind and turns them into a message or a log
entry, so the change at each call site is one more class in a catch list.

Everything else keeps working. Webhooks are received and stored, both reconciliation polls run,
every screen renders, and the lists stay current. Only the two acts that create value are
refused. A customer whose subscription lapsed therefore sees exactly what is waiting for them,
nothing piles up unseen, and the moment they renew they are working again.

## 4. Components

| Class | Responsibility |
|---|---|
| `LicenseGate` | Interface with one method, returning a `LicenseVerdict` |
| `ServiceBackedLicenseGate` | The real implementation: reads storage, verifies, falls back to the emergency fetch |
| `AlwaysLicensedGate` | Agrees to everything. Wired while the service does not exist, see section 10 |
| `LicenseVerdict` | Value object: state, customer name, time of the last confirmation |
| `LicenseState` | Enum: `Licensed`, `NoKeyConfigured`, `Rejected`, `Unreachable`, `VersionNotCovered` |
| `LicenseToken` | Parses and holds the signed artefact, exposes its fields |
| `MalformedLicenseToken` | Thrown when a string is not a license artefact at all |
| `LicenseSignatureVerifier` | Ed25519 verification against the list of accepted public keys |
| `LicenseEvaluator` | Decides whether a stored artefact is still usable, and what verdict it yields |
| `PluginVersion` | Reads the installed version from the plugin's own `composer.json` |
| `LicenseClient` | The one request against the licensing service |
| `LicenseServiceUnavailable` | Thrown when the service cannot be reached or answers unusably |
| `LicenseStore` | Reads and writes the artefact in Kimai's system configuration |
| `CheckLicenseCommand` | The scheduled refresh, sibling of the existing key check command |
| `LicenseRequiredException` | Thrown by all three entry points listed in section 3, carries the verdict |
| `LicenseVerdictMessageFormatter` | Turns a verdict's state and reason into the translated sentence shown to a person |
| `LicenseVerdictPresenter` | Reduces a verdict to the plain array a controller hands to its template for the banner |

`LicenseVerdictMessageFormatter` and `LicenseVerdictPresenter` were not anticipated when this
table was first written. Both exist because the banner described in section 8 needed something
between the raw `LicenseVerdict` and the Twig template, one to produce the sentence and one to
produce the data structure the template renders; splitting the two kept each responsible for one
thing rather than growing a single class that both formats text and shapes template data.

Which gate implementation is wired is decided by an alias, not a container parameter carrying a
value, so the switched off state is a configuration value rather than a branch inside the logic,
and is therefore testable in its own right. Section 10 describes what else has to be set for the
switched on state to actually work.

## 5. The protocol

**Request.** A POST carrying exactly two fields: the license key the customer entered, and the
plugin's own version, read from its `composer.json` through Kimai's `PluginMetadata`. Nothing
else. No instance identifier, no host name, no Kimai version. The service therefore cannot tell
one installation from another by anything inside the request body, and the cost is accepted: a
key used on fifty instances looks the same as a key used on one. This does not keep the request
out of data protection territory entirely: making the connection at all discloses the customer's
server address to the licensing service, the way any outbound HTTP request does, and an IP
address is personal data under the General Data Protection Regulation regardless of what the
body carries. The body is deliberately minimal; the connection itself is not anonymous. Anyone
writing the privacy policy for the sales site from this section should write it against that
narrower claim.

**The address of the service is a constant**, exposed as a container parameter whose default is
the production URL. It is deliberately not a system configuration field. A configurable address
would let a customer point the plugin at a server of their own and issue themselves a license.
The parameter exists so that a test can point it somewhere else, and a customer installation has
no way to set it.

**Response.** One string in two parts, separated by a dot: the base64url encoded JSON body, and
the base64url encoded Ed25519 signature over that encoded body. The whole string is what gets
stored.

That shape is chosen so that a second delivery route can be added later without touching the
protocol: the same string pasted into a configuration field, delivered by mail, would be
accepted by the same code. That route is not being built now.

**Body fields.**

| Field | Meaning |
|---|---|
| `licensed` | Whether this version is covered by the subscription |
| `customer` | Name to display, so a person can see which license is installed |
| `version` | The plugin version this answer was issued for |
| `issued_at` | When the service issued it |
| `recheck_after` | When the plugin should ask again |
| `valid_until` | How long this answer may be used without asking again |
| `reason` | Absent when licensed; otherwise `expired`, `revoked`, `unknown_key` or `version_not_covered` |

**The subscription rule lives in the service, not here.** The plugin knows its version but not
its release date, so it cannot decide on its own whether a lapsed subscription still covers the
installed version. It sends the version and the service answers. The rule can then be changed
without anybody updating anything, and the plugin stays ignorant of a policy that is a commercial
decision rather than a technical one.

The consequence is that a stored answer belongs to exactly one version. After a plugin update the
stored answer no longer applies and a fresh answer is fetched, which is precisely the moment when
the check matters.

**A refusal is stored exactly like an approval.** An answer carrying `licensed: false` is a valid
answer and is kept, with `recheck_after` deciding how soon the plugin asks again. Discarding
refusals would mean asking on every single conversion attempt, which would hammer the service
precisely when a customer is clicking repeatedly because nothing works.

**Verification happens on every read, not only on fetch.** Signature valid against one of the
accepted public keys, `version` equal to the installed version, `valid_until` not in the past. If
any of those fails the license counts as unconfirmed. The stored copy is therefore not a trust
anchor: tampering with it makes it worthless rather than useful.

**Two honest limitations.** `valid_until` is measured against the customer's own clock, so
turning the clock back extends a stale answer. And an old answer could be restored from a
backup. Neither is worth defending against, because the subscription rule already grants the
installed version its run: what could be gained this way is what the customer is entitled to
anyway.

## 6. Storage

Two keys in Kimai's system configuration.

`lexware_sync.license_key` is what the customer enters, on the same screen as the Lexware API
key and rendered the same way, as a password field that keeps the stored value when left blank.

`lexware_sync.license_token` is the signed artefact, written by the plugin and never rendered as
a form field, because nobody should edit it by hand. It needs no protection: it is signed, and
an edit only destroys it. A dedicated database table for a single string would be work without
a return, so there is no migration in this specification.

**A stored artefact is tied to the key it was fetched for.** The body deliberately carries no
reference to the license key, because the request sends as little as possible, so the store keeps
a hash of the key alongside the artefact. When the configured key no longer matches that hash the
artefact is ignored and a fresh one is fetched. Without this, entering a different key would
leave the previous answer in force, which is both wrong and the obvious way to try to cheat: buy
one license, then swap in a key that was never valid.

## 7. The three paths

**Scheduled.** `kimai:lexware-sync:check-license`, run daily from cron next to the existing key
check. In the ordinary case it does nothing: a valid artefact for the installed version whose
`recheck_after` has not passed ends the run immediately. Otherwise it asks the service, verifies,
stores, and reports. When the result is not licensed it exits with a failure code, so that a
monitoring system or the cron mail notices before a user does.

**Emergency.** Taken only when no usable artefact exists at all: a fresh installation, the first
run after a plugin update, or an expired `valid_until`. One attempt, a short timeout. A failure
is remembered in Kimai's cache for fifteen minutes, so that the following requests do not each
run into the same timeout and make the screen crawl.

**Read.** The common case, and it touches no network. Read the artefact, verify it, return the
verdict.

All three go through the same verification code. The scheduled path and the emergency path
differ only in what triggers them and how loudly they report.

## 8. Error handling and what a person sees

Failures are told apart rather than lumped together.

- Service unreachable, artefact still within `valid_until`: nothing changes, one log entry. A
  failure on the author's side must not stop a paying customer from working.
- Service unreachable, nothing usable stored: state `Unreachable`, and the screen says so, so
  that a customer behind a strict firewall knows what to open rather than guessing.
- Signature invalid: counts as not licensed and is logged loudly. It means either tampering or a
  botched key rotation, and both need to be visible.
- A malformed response is treated as unreachable, with a log entry that says which it was.

Both screens show a banner carrying the state and, when known, the customer name from the
license. The action buttons are disabled while unlicensed. That is courtesy only. The actual
refusal stays in the processor, because a disabled button stops nobody who sends the request
themselves.

## 9. Key rotation

Verification runs against a list of accepted public keys, which holds exactly one entry today.

Without that list, changing the signing key later would require every customer to update at the
same moment as the service switches over. With it, a new key can be added to the list, shipped,
and only then used for signing. The cost now is one line.

## 10. The switch this ships with

`AlwaysLicensedGate` is wired until the licensing service exists. It is a service definition, not
a condition inside the logic, so nothing in the checking code is dead or untested while the
switch is off.

There are two places to touch, in this order, not the one this section originally claimed.

First, the alias in `Resources/config/services.yaml` that binds `LicenseGate` to one of the two
implementations. Not an environment variable, not a system configuration field a customer could
see, and no branch anywhere in the checking code. Switching the mechanism on means pointing that
alias at `ServiceBackedLicenseGate`.

Second, the `lexware_sync.license_public_keys` parameter, also in `Resources/config/services.yaml`,
which ships as an empty list because the licensing service's real signing key does not exist yet.
Forgetting this one is not a no-op: `ServiceBackedLicenseGate` fetches a token from the real
service, hands it to `LicenseSignatureVerifier`, and an empty accepted key list makes every
signature check fail, genuine license included. The gate then treats the fetch as unusable,
logs an error, remembers the failure for fifteen minutes and reports `Unreachable`. A customer
with a perfectly valid license sees exactly what someone behind a broken firewall would see,
and nothing in the logs says why beyond the one error line, because as far as the checking code
is concerned the service handed back an artefact it cannot use. The public key generated at the
end of the implementation, described in section 13, has to be in this parameter before the alias
above is switched, or the switch achieves nothing but a confusing failure mode for every
customer.

Removing the switch afterwards means deleting three things: the alias, the
`AlwaysLicensedGate` class, and its test. One implementation is then left, and the interface can
stay or go depending on whether a second one is ever wanted. Nothing else in the design refers to
the switch, which is the point of putting it there rather than inside the logic.

The switched off state gets its own test, asserting that a conversion succeeds with no artefact
present at all. Without that test nobody would notice if the switch quietly stopped doing
anything.

## 11. Testing strategy

**Unit**, no kernel and no database, with a throwaway key pair the test generates itself, the way
the existing webhook signature test already does for RSA. Covered: a valid signature, a modified
body, a modified signature, a signature from a foreign key, a key absent from the accepted list.
Then parsing the artefact: wrong number of parts, broken base64, broken JSON. Then deriving the
verdict: version mismatch, expired `valid_until`, each of the four rejection reasons, and the
licensed case. Two more classes ended up with unit tests that this section did not originally
call for: `LicenseClientTest` covers that only the key and the version are sent and that a missing
artefact, an error status and a non JSON body all count as unavailable, and
`LicenseVerdictMessageFormatterTest` covers that the state and the reason each produce a distinct
translated message. Both belong at this level for the same reason as the rest: neither needs a
kernel or a database to be meaningful.

**Functional**, with kernel and database. The blunt one matters most: all three entry points
named in section 3, not only the two `convert()` methods this section originally named, refuse to
proceed without a license and go through with one, in `ConversionRequiresALicenseTest`. Then the
command, which stores an artefact and exits with a failure code when unlicensed, in
`CheckLicenseCommandTest`. Then the emergency path, attempted exactly once and not again for
fifteen minutes, in `ServiceBackedLicenseGateTest`, which also covers the read path directly
against the gate rather than only through a screen. Then one test file per concern on the
screens, `LicenseBannerTest`, covering the banner and the disabled buttons on both the triage
screen and the invoice assignment screen. Three further functional tests exist that this section
did not anticipate: `LicenseConfigurationTest`, asserting the license key is registered as a
system configuration field ahead of the API key, `LicenseStoreTest`, covering storage and
retrieval of the artefact including what happens when the configured key changes, and
`ShippedLicenseWiringTest`, which is the test section 10 promises for the switched off state: it
asserts both that `LicenseGate` resolves to `AlwaysLicensedGate` in a kernel booted from the
shipped configuration and that a conversion through that kernel succeeds with no license key and
no stored artefact at all.

This needs a fake for a second service. Rather than write a second fake, the recording and
stubbing logic moves out of `FakeLexwareHttpClient` into something both can use. That is test
code we own, and the alternative is two nearly identical classes that drift apart.

**No migration test**, because no schema changes. **No contract test**, because the counterpart
does not exist yet; when it is built, its tests belong to its own project. The fake here is where
the two projects' understanding of the protocol will first disagree, which is worth knowing when
that day comes.

## 12. Deliberately not in this specification

- The licensing service, the sales page, and the Paddle connection. Separate project.
- License delivery by mail or by pasting an artefact into the configuration. The protocol is
  shaped so this can be added later without changing anything else, and it is not being built.
- Counting or limiting the number of installations. Ruled out with the decision to send nothing
  that identifies an instance.
- Any attempt to make the check hard to remove. The plugin is MIT licensed and its source is
  readable; effort spent here would buy nothing.

## 13. Open items

The private signing key does not exist yet. It is generated at the end of the implementation and
written to a file outside this repository, because it belongs to the service project and must not
enter a chat transcript or a commit. The public key goes into the source as a constant, and until
it exists the tests use their own generated pair, so nothing waits on it.
