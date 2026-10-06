# Writes are transactional, so MongoDB is a replica set everywhere

`factory::save()`, `saveMany()`, `delete()`, `deleteMany()` and `deleteManyBy()` each open a
transaction when they are not handed a session. MongoDB offers transactions only on a replica set
or a sharded cluster, so every Environment an Application runs in — production, CI, and a
developer's machine — must provide one. A standalone `mongod` is not a supported configuration.

The reason is that a save is not one write. A single `save()` writes the document, advances any
`#[autoIncrement]` counters, and dispatches the Model's Embedded Copies into every other collection
that holds one. A partial application of that set does not fail a request; it leaves Embedded
Copies disagreeing with the Model they copy, which is a corruption nothing detects and no later
save repairs.

## Considered Options

**Open a transaction only when the save spans more than one write.** A Model with no `#[foreignKey]`
Embedded Copies and no `#[autoIncrement]` field really does perform exactly one write, with nothing
to be atomic about, and skipping the session there would let a standalone `mongod` serve an
Application completely. This is the change a future reader will propose on finding a session opened
around a single-document write, so it is worth saying why it was rejected.

It trades an unconditional invariant for a conditional one. "A save is atomic" becomes "a save is
atomic when the framework judged it needed to be" — and that judgement is made from attributes on a
class that changes over time. Adding a `#[foreignKey]` to an existing Model would silently move it
from one regime to the other, and the failure that follows is invisible in the failing request and
surfaces later as data that disagrees with itself. The condition is also not local: whether a save
is single-write depends on which *other* Models embed a copy of this one, which the Model being
saved cannot see.

**Require a replica set only in production, and let development run standalone.** Rejected because
it makes the Environments differ in a way that hides exactly the class of bug transactions exist to
prevent: development would pass on writes production would roll back, and vice versa.

## Consequences

The cost falls entirely on development and CI. Production was never affected — Applications connect
to a managed cluster over `mongodb+srv://`, which is a replica set already — which is why the
constraint went unwritten until someone tried to run a scaffolded Application locally and found that
reads worked and writes did not.

A single-member replica set satisfies it, and is what the application template's compose stack now
runs. The failure mode when it is missing is worth recognising on sight: reads succeed, so an
Application starts, answers `/health`, and lists documents; only writing fails, with *"Transaction
numbers are only allowed on a replica set member or mongos"*.
