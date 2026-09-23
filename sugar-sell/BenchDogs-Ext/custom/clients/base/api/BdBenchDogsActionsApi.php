<?php

/**
 * RETIRED (G280 / 🔒 1567, 0.9.42-rc69). This file intentionally declares no class
 * and registers no route.
 *
 * It carried BdBenchDogsActionsApi and, last, one admin route -
 * `POST bd-tools/repair-ui` - that re-ran this package's own deployed-metadata
 * steps: the Bench Dogs Quotes panel removal (K-2), the retired Opportunity
 * marker removal (K-3) and the customer-group placement. K-2 and K-3 are SPENT:
 * the one-off "Retire Bench Dogs Residue" ran on every QA tenant (et 1.0.0
 * 2026-09-22 22:22Z, stock and Ophir 1.0.1 2026-09-23 00:58Z, G234 CLOSED) and
 * nothing re-adds either. post_install already places the customer-group fields
 * on every install. So the route had no job left, and 🔒 1520 makes removal the
 * default for anything that is not customer-category code.
 *
 * WHY THE PATH STILL SHIPS, EMPTY. ServiceDictionary::buildAllDictionaries()
 * require_once's every custom/clients/*\/api/*.php on a REST rebuild and
 * registers the class named after the file when it exists
 * (SugarEnt 26.1.0 include/api/ServiceDictionary.php:115-131). Module Loader
 * never deletes a file a later build stops shipping, so DROPPING this path would
 * leave rc68's body - and the live route - on every upgraded tenant. Shipping it
 * empty is what unregisters the route there: the file loads, defines nothing,
 * and the dictionary builder moves on ("Either the class doesn't exist ... we
 * move on"). Keeping rc68's BODY instead is not an option either: repairUi()
 * require_once's BdQuotesLayoutExtensions.php with no file_exists guard, and
 * this build no longer ships that class, so on a fresh tenant the route would
 * be a compile fatal that no catch can see.
 *
 * DROPPABLE in the build after every tenant carrying Bench Dogs has taken this
 * one: from then on the file on disk is this empty body, whatever later builds do.
 */
