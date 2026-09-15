---
id: BD-L-0017
title: Introspection output is program output — `repr()` of a config object is a credential sink
rule: Never print a whole object to discover its shape; enumerate the attribute NAMES you want and print only those, and give any object holding a secret a redacting `__repr__`.
severity: critical
subsystems: [benchdogs-sugar, config-and-secrets, release-evidence]
paths:
  - scripts/**
---

# Introspection output is program output — `repr()` of a config object is a credential sink

**What happened.** A read-only diagnostic needed to know which attributes a connector destination object
carried. The quickest way to find out is to print the object, so a probe printed **`repr(CrmDestination)`**.
**That dataclass renders `password=` in its repr.** A **partial** Bench service-account password reached an
agent transcript before the output was truncated. The value was never typed, chosen, stored or written to any
file — **and it was still disclosed**, because a transcript is a place a secret can end up.

**Why nothing caught it.** Every guard in this area points at the paths where secrets are *used*: the delivery
path (`op read → ssh → docker cp → encrypt`, never printed) was correct, and error reporting already went
through a redacting reporter. **Nobody had guarded the SUCCESS path of a read-only shape query**, because
introspection does not feel like output. It is output.

**The generated `__repr__` is the trap.** A Python dataclass renders **every** field by default, so a
credential field is disclosed by any `print(obj)`, any `f"{obj}"`, any logged exception carrying the object,
any debugger frame dump, and any `pytest` assertion diff that includes it.

**Rules.**
1. **Never print a whole object to learn its shape.** Print the **names**: `sorted(vars(obj))` or
   `[f.name for f in dataclasses.fields(type(obj))]`. Then print only the fields you actually need — in the
   case above, `base_url` and `api_base_path`.
2. **Give any object holding a secret a redacting `__repr__`**, or declare the field with
   `field(repr=False)`. A type that can be printed safely is worth more than a rule that it must not be.
3. **Treat a partial value as a full disclosure.** Rotate. *Declining to USE a pasted or printed secret is
   necessary and not sufficient.*
4. **Journal the exposure at the moment it happens** — name and path, **never the value**. An obligation that
   lives only in a conversation is not in the record; three credentials sat in exactly that state for hours on
   this release.
5. **Rotation and permission-narrowing are orthogonal, and each closes something the other cannot.** The
   *standing* exposure (the account may re-read the value by design) is closed by **narrowing**; **this
   disclosure** (the value left the instance) is closed only by **rotation**. *Rotation is insufficient* does
   not entail *rotation is unnecessary*.
6. **Leave a warning where the next lane will trip.** The probe that did this now carries a docstring saying
   why it prints two named attributes and not the object.

**Related.** Core lesson **L-0202** covers the terminal-**error** boundary (a traceback bypassing the
redacting reporter); this lesson is its complement on the **success** path, and the connector lane should
mirror it there. Sugar lesson **L-0055** (masking a secret in a config response) covers the product surface.
