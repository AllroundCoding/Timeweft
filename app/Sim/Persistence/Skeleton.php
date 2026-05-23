<?php

namespace App\Sim\Persistence;

/**
 * Marks a canonical, persisted type — part of the sparse **skeleton** (design doc 01): the
 * path-dependent timeline (canonical events, the entities they concern, the ledgers between them) that
 * *must* be stored because it cannot be regenerated from the seed alone — you only know who exists in
 * year 800 by having run the world there.
 *
 * The counterpart of {@see Texture}. A type implements `Skeleton` to declare "I am source-of-truth state
 * that persistence (TWT-28/30) saves and a checkpoint (TWT-32) carries." The full contract — and which
 * fields of a mixed entity are skeleton vs texture — lives in docs/conventions.md.
 */
interface Skeleton {}
