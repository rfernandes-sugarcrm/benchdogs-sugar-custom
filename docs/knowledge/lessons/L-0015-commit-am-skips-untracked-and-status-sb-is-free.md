---
id: BD-L-0015
title: '`git commit -am` skips untracked files, and `git status -sb` is the free positive control'
rule: Stage with `git add -A` when a change adds files, verify a split patch by applying it from a pristine tree, and print `git status -sb` before any claim derived from a local ref.
severity: high
subsystems: [benchdogs-sugar, package-build, release-evidence]
paths:
  - scripts/**
---

# `git commit -am` skips untracked files, and `git status -sb` is the free positive control

Two defects from one release, both in the machinery that **produces the evidence** rather than in the product.

## 1. `-a` stages only TRACKED modified files

A deliverable was split into `phase-b1` (the re-parent) and `phase-b2` (the retirement) by applying b1,
committing a marker, then diffing the tree for b2. The marker used **`git commit -am`**. **b1 is almost
entirely NEW files**, so `-a` staged none of them, the marker commit was empty of b1, and **b2 came out as a
superset of b1.** Applying b1 then b2 failed with eight `already exists in working directory` errors.

**It was caught only because the end-to-end replay ran from a pristine tree.** `git diff --stat` would have
looked right — both patches list plausible files. **The patches were wrong in a way that only APPLYING them
could reveal.** Fixed with `git add -A` before the marker commit; b2 then re-adds **0** b1 files.

## 2. A "branch is unmerged" claim was false because the checkout was ten commits behind

A design note's load-bearing caveat read *"only live today if the deployed extension carries `b06ee49`, which
is on an **unmerged** branch."* The claim came from a `merge-base` test **against a local ref**. One command
refuted it: **`git status -sb` → `## main...origin/main [behind 10]`.** After `git fetch`, all three cited
revisions were ancestors of `origin/main`, and the commit the note called unreachable **was `origin/main`
HEAD**. The caveat was itself a **rule-9 false absence**.

The same trap has a sibling: a repository whose fetch **refspec is narrower than you assume**
(`+refs/heads/staging:refs/remotes/origin/staging` — staging only, not main) will answer every question about
`main` with a confident, stale, local answer.

**Rules.**
1. **`git add -A` whenever a change adds files.** `-am` is for edits to tracked files and nothing else.
2. **Verify a split patch by APPLYING it from pristine, in order.** `--stat` is a description, not a test.
   Assert that the second patch re-adds zero files from the first.
3. **Print `git status -sb` before any claim about merged / ahead / behind / reachable.** It costs nothing and
   it is the positive control a `merge-base` against a local ref silently needs and does not get.
4. **`git fetch` first, then name the ref you tested against** — `origin/main`, not `main`. Check the refspec
   if the answer surprises you.
5. **`git ls-remote` is the only authority on "pushed."**

**A worked consequence from the same day:** a vault lesson was about to be numbered `L-0007` from a working
tree holding six files. `git ls-tree origin/main` showed **nine** — the checkout was 13 commits behind and
`L-0007`, `L-0008`, `L-0009` already existed upstream. **The collision was avoided by running rule 3 on the
lesson that states rule 3.**

**Related.** `BD-L-0009` (prove you scanned the right bytes), `BD-L-0013` (prove where a change landed).
