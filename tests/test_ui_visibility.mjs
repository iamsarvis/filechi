/**
 * FileChi Admin UI Visibility Pure Functions Tests (Step U)
 *
 * Runs with Node.js built-in test runner: `node --test tests/test_ui_visibility.mjs`
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { visibleTabs, showProtectedPath } = require('../assets/js/admin.js');

test('visibleTabs: WooCommerce active shows all 4 tabs', () => {
	const data = {
		integrations: [
			{ id: 'woocommerce', label: 'WooCommerce', active: true },
		],
		woocommerce: { active: true, version: '9.5.0', hpos_enabled: true },
	};

	const tabs = visibleTabs(data);
	const names = tabs.map((t) => t.name);

	assert.deepEqual(names, ['connections', 'media_rules', 'woocommerce', 'migration_logs']);
	assert.equal(tabs.find((t) => t.name === 'woocommerce')?.title, 'WooCommerce');
});

test('visibleTabs: WooCommerce inactive hides WooCommerce tab', () => {
	const data = {
		integrations: [
			{ id: 'woocommerce', label: 'WooCommerce', active: false },
		],
		woocommerce: { active: false, version: '', hpos_enabled: false },
	};

	const tabs = visibleTabs(data);
	const names = tabs.map((t) => t.name);

	assert.deepEqual(names, ['connections', 'media_rules', 'migration_logs']);
	assert.equal(names.includes('woocommerce'), false);
});

test('visibleTabs: Empty or null data hides WooCommerce tab safely', () => {
	const emptyDataTabs = visibleTabs({});
	assert.deepEqual(emptyDataTabs.map((t) => t.name), ['connections', 'media_rules', 'migration_logs']);

	const nullDataTabs = visibleTabs(null);
	assert.deepEqual(nullDataTabs.map((t) => t.name), ['connections', 'media_rules', 'migration_logs']);
});

test('visibleTabs: Fallback to woocommerce or wc object if integrations array missing', () => {
	const fallbackWc = visibleTabs({ woocommerce: { active: true } });
	assert.ok(fallbackWc.some((t) => t.name === 'woocommerce'));

	const fallbackShortWc = visibleTabs({ wc: { active: true } });
	assert.ok(fallbackShortWc.some((t) => t.name === 'woocommerce'));
});

test('showProtectedPath: Returns true when WooCommerce integration is active', () => {
	const activeData = {
		integrations: [
			{ id: 'woocommerce', label: 'WooCommerce', active: true },
		],
	};
	assert.equal(showProtectedPath(activeData), true);
});

test('showProtectedPath: Returns true when another protected integration (EDD) is active', () => {
	const eddData = {
		integrations: [
			{ id: 'woocommerce', label: 'WooCommerce', active: false },
			{ id: 'edd', label: 'Easy Digital Downloads', active: true },
		],
	};
	assert.equal(showProtectedPath(eddData), true);
});

test('showProtectedPath: Returns false when WooCommerce is inactive and no protected integration is active', () => {
	const inactiveData = {
		integrations: [
			{ id: 'woocommerce', label: 'WooCommerce', active: false },
		],
	};
	assert.equal(showProtectedPath(inactiveData), false);

	const otherData = {
		integrations: [
			{ id: 'unrelated_plugin', label: 'Unrelated', active: true },
		],
	};
	assert.equal(showProtectedPath(otherData), false);

	assert.equal(showProtectedPath({}), false);
	assert.equal(showProtectedPath(null), false);
});
