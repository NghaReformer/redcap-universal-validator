/*
 * tests/use_engine_min.cjs - load js/engine.min.js wherever a suite requires
 * js/engine.js (PRF-7). Preloaded by tests/engine_min_js.cjs:
 *   node -r ./tests/use_engine_min.cjs tests/<suite>.cjs
 * A suite that loaded the engine this way says so on stderr as it exits, so
 * the runner can tell it from one that read the source as text.
 */
'use strict';
const Module = require('module');
const path = require('path');

const SRC = path.join(__dirname, '..', 'js', 'engine.js');
const MIN = path.join(__dirname, '..', 'js', 'engine.min.js');
const resolve = Module._resolveFilename;
let used = false;
Module._resolveFilename = function (request, parent, isMain, options) {
  const file = resolve.call(this, request, parent, isMain, options);
  if (file !== SRC) return file;
  used = true;
  return MIN;
};
process.on('exit', () => { if (used) process.stderr.write('\nUV_ENGINE_MIN_LOADED\n'); });
