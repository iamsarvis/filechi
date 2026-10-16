/**
 * FileChi FTPS Trust Flow & Modal State Transitions Test (F2b.1)
 *
 * Runs with Node.js built-in test runner: `node --test tests/test_trust_flow.mjs`
 */

import test from 'node:test';
import assert from 'node:assert/strict';

/**
 * State machine simulating the React admin state for provider editing,
 * trust key modal, and connection testing.
 */
class TrustFlowStateMachine {
	constructor(initialProvider = null) {
		this.editingProvider = initialProvider || {
			id: 1,
			name: 'DirectAdmin FTPS',
			driver: 'ftps',
			settings: {
				host: '130.185.79.177',
				port: 21,
				username: 'ftpuser',
				password: 'secretpassword',
				tls_pin: '',
			},
		};
		this.isModalOpen = true;
		this.trustDialogData = null;
		this.testResult = null;
		this.isTesting = false;

		// Spies for API calls
		this.apiCalls = [];
	}

	async apiFetch(options) {
		this.apiCalls.push(options);
		const { path, data } = options;

		if (path === '/filechi/v1/test-connection') {
			// If no pin is provided, simulate server discovering untrusted certificate
			if (!data.settings.tls_pin) {
				return {
					needs_trust: true,
					subject: '130.185.79.177',
					issuer: 'DirectAdmin Self-Signed CA',
					algorithm: 'RSA (2048 bits)',
					fingerprint: 'sha256//a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v=',
					valid_from: '2026-01-01',
					valid_to: '2027-01-01',
				};
			}

			// If pin is provided and matches, return staged success
			if (data.settings.tls_pin === 'sha256//a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v=') {
				return {
					success: true,
					message: 'FTPS (Explicit TLS) connection successful! (Round-trip: 45ms)',
					stages: [
						{ name: 'connect', ok: true, detail: 'Control channel connected on port 21' },
						{ name: 'tls', ok: true, detail: 'AUTH TLS OK, public key pin verified' },
						{ name: 'login', ok: true, detail: 'Authenticated as ftpuser' },
						{ name: 'directory', ok: true, detail: 'Root path verified' },
						{ name: 'data_connection', ok: true, detail: 'Passive mode data connection OK (port 35153)' },
						{ name: 'write_probe', ok: true, detail: 'Upload probe verified and cleaned up' },
					],
				};
			}

			// Mismatched pin
			return {
				success: false,
				message: 'FTPS error: PINNED KEY MISMATCH — server certificate public key does not match pinned fingerprint.',
				details: { curl_errno: 90 },
			};
		}

		throw new Error('Unknown path: ' + path);
	}

	async handleTestConnection(customSettings = null, initialMessage = null) {
		this.isTesting = true;
		this.testResult = {
			isTesting: true,
			message: initialMessage || 'Testing connection…',
		};

		const currentSettings = customSettings || (this.editingProvider ? this.editingProvider.settings : {});

		try {
			const res = await this.apiFetch({
				path: '/filechi/v1/test-connection',
				method: 'POST',
				data: {
					id: (this.editingProvider && this.editingProvider.id) || null,
					driver: (this.editingProvider && this.editingProvider.driver) || 'ftps',
					settings: currentSettings,
				},
			});

			if (res && res.needs_trust) {
				this.trustDialogData = res;
				this.testResult = null;
			} else {
				this.testResult = res;
			}
		} catch (err) {
			this.testResult = {
				success: false,
				message: err.message || 'Connection test failed to respond.',
			};
		} finally {
			this.isTesting = false;
		}
	}

	handleTrustKey(e) {
		if (e && e.stopPropagation) {
			e.stopPropagation();
		}
		if (!this.trustDialogData || !this.trustDialogData.fingerprint) {
			this.trustDialogData = null;
			return;
		}

		const pin = this.trustDialogData.fingerprint;
		const currentProv = this.editingProvider || {};
		const updatedSettings = Object.assign({}, currentProv.settings || {}, { tls_pin: pin });
		const updatedProvider = Object.assign({}, currentProv, { settings: updatedSettings });

		// 1. Store the pin directly in editing state
		this.editingProvider = updatedProvider;

		// 2. Only trust dialog closes; Edit modal stays open
		this.trustDialogData = null;
		this.isModalOpen = true;

		// 3. Show intermediate notice inside Edit modal
		this.testResult = {
			isTesting: true,
			message: 'Pin saved — testing connection…',
		};

		// 4. Use new value directly when re-running test without waiting for React state commit
		this.handleTestConnection(updatedSettings, 'Pin saved — testing connection…');
	}

	handleCancelTrust(e) {
		if (e && e.stopPropagation) {
			e.stopPropagation();
		}
		this.trustDialogData = null;
		this.isModalOpen = true;
		this.testResult = {
			success: false,
			message: 'Remote public key was not trusted. Connection refused.',
		};
	}
}

test('F2b.1 Trust Flow: First click on Trust Key stores pin and immediately tests connection', async () => {
	const machine = new TrustFlowStateMachine();

	// Step 1: Initial state
	assert.equal(machine.isModalOpen, true, 'Edit modal must be open initially');
	assert.equal(machine.trustDialogData, null, 'Trust dialog must be closed initially');
	assert.equal(machine.editingProvider.settings.tls_pin, '', 'Pin must be empty initially');

	// Step 2: User clicks "Test Connection"
	await machine.handleTestConnection();

	// Server returned needs_trust
	assert.equal(machine.isModalOpen, true, 'Edit modal must remain open when trust dialog opens');
	assert.ok(machine.trustDialogData, 'Trust dialog must receive certificate data');
	assert.equal(machine.trustDialogData.needs_trust, true);
	assert.equal(machine.trustDialogData.fingerprint, 'sha256//a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v=');
	assert.equal(machine.testResult, null, 'testResult is cleared while trust dialog is pending');

	// Step 3: User clicks "Trust this key" on the first try
	let stoppedPropagation = false;
	const mockEvent = {
		stopPropagation: () => {
			stoppedPropagation = true;
		},
	};

	machine.handleTrustKey(mockEvent);
	assert.equal(stoppedPropagation, true, 'Event propagation must be stopped to avoid backdrop clicks');

	// Verify synchronous transition before apiFetch completes
	assert.equal(machine.trustDialogData, null, 'Trust dialog must close immediately');
	assert.equal(machine.isModalOpen, true, 'Edit modal MUST STAY OPEN after trusting key');
	assert.equal(
		machine.editingProvider.settings.tls_pin,
		'sha256//a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v=',
		'Pin MUST be stored in editingProvider state on the FIRST trust click'
	);
	assert.ok(machine.testResult, 'Intermediate test notice must be set');
	assert.equal(machine.testResult.isTesting, true);
	assert.equal(machine.testResult.message, 'Pin saved — testing connection…');

	// Wait for the re-test to complete
	await Promise.resolve(); // flush microtasks
	assert.equal(machine.apiCalls.length, 2, 'Two API calls must have been made (1st test, 2nd test with pin)');
	const secondCall = machine.apiCalls[1];
	assert.equal(
		secondCall.data.settings.tls_pin,
		'sha256//a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v=',
		'Second API call must directly carry the new tls_pin value without reading uncommitted state'
	);

	// Verify final state
	assert.equal(machine.isModalOpen, true, 'Edit modal MUST remain open to display test result');
	assert.equal(machine.testResult.success, true, 'Test result must be marked success');
	assert.ok(machine.testResult.message.includes('successful'));
	assert.equal(machine.testResult.stages.length, 6, 'Staged checklist must be present');
});

test('F2b.1 Trust Flow: Cancel Trust keeps Edit modal open and reports refusal', () => {
	const machine = new TrustFlowStateMachine();
	machine.trustDialogData = {
		needs_trust: true,
		fingerprint: 'sha256//dummy',
	};

	let stoppedPropagation = false;
	const mockEvent = {
		stopPropagation: () => {
			stoppedPropagation = true;
		},
	};

	machine.handleCancelTrust(mockEvent);

	assert.equal(stoppedPropagation, true);
	assert.equal(machine.trustDialogData, null, 'Trust dialog must close on cancel');
	assert.equal(machine.isModalOpen, true, 'Edit modal must remain open on cancel');
	assert.equal(machine.editingProvider.settings.tls_pin, '', 'Pin must NOT be stored on cancel');
	assert.equal(machine.testResult.success, false);
	assert.ok(machine.testResult.message.includes('Remote public key was not trusted'));
});
