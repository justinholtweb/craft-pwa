/**
 * Asserts the service worker's matcher agrees with the PHP one, case for case.
 *
 * Run with: node tests/js/route-parity.mjs
 *
 * The worker is a browser file, so it cannot simply be imported here — it references `self` and
 * registers event listeners at load. What is extracted instead are the two pure functions that do
 * the matching, evaluated against the same fixture RouteTest uses. If this passes and RouteTest
 * passes, the control panel's route tester is telling the truth about what the browser will do.
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(join(here, '../../src/resources/sw.js'), 'utf8');
const fixture = JSON.parse(readFileSync(join(here, '../fixtures/route-cases.json'), 'utf8'));

function extract(name) {
  const start = source.indexOf(`function ${name}(`);

  if (start === -1) throw new Error(`${name}() is not in sw.js any more — this test is out of date.`);

  // Brace matching from the function's opening brace: crude, and adequate for two small
  // functions that are checked to exist by name first.
  let depth = 0;
  let index = source.indexOf('{', start);
  const from = index;

  for (; index < source.length; index++) {
    if (source[index] === '{') depth++;
    if (source[index] === '}') depth--;
    if (depth === 0) break;
  }

  return source.slice(start, index + 1);
}

const CONFIG = { routes: fixture.routes };
const scope = new Function(
  'CONFIG',
  `${extract('matchRoute')}\n${extract('globMatches')}\nreturn { matchRoute, globMatches };`
)(CONFIG);

let failures = 0;

for (const testCase of fixture.cases) {
  const url = new URL('https://' + testCase.host + testCase.path);
  const matched = scope.matchRoute(url, { destination: testCase.destination });
  const index = matched ? fixture.routes.indexOf(matched) : null;

  if (index !== testCase.expect) {
    failures++;
    console.error(`FAIL  ${testCase.path} (${testCase.destination || 'no destination'}) → rule ${index}, expected ${testCase.expect}`);
    console.error(`      ${testCase.why}`);
  }
}

for (const glob of fixture.globs) {
  const got = scope.globMatches(glob.pattern, glob.subject);

  if (got !== glob.expect) {
    failures++;
    console.error(`FAIL  glob "${glob.pattern}" against "${glob.subject}" → ${got}, expected ${glob.expect}`);
  }
}

const total = fixture.cases.length + fixture.globs.length;

if (failures > 0) {
  console.error(`\n${failures} of ${total} cases disagree with the PHP matcher.`);
  process.exit(1);
}

console.log(`${total} cases: the worker's matcher agrees with the PHP one.`);
