/**
 * FileChi Admin Application
 * Built with @wordpress/element, @wordpress/components, and @wordpress/api-fetch
 */

(function (wp) {
	'use strict';

	const { createElement: el, useState, useEffect, useCallback } = (wp && wp.element) || {};
	const {
		TabPanel,
		Button,
		TextControl,
		SelectControl,
		ToggleControl,
		Notice,
		Spinner,
		Modal,
	} = (wp && wp.components) || {};
	const apiFetch = (wp && wp.apiFetch) || function () {};

	function formatBytes(bytes) {
		if (!bytes || bytes === 0) return '0 B';
		const k = 1024;
		const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
		const i = Math.floor(Math.log(bytes) / Math.log(k));
		return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
	}

	// Reusable Trust Server Public Key Dialog (FTPS & SFTP)
	function TrustKeyDialog({ title, rows, onTrust, onCancel }) {
		return el(Modal, {
			title: title || 'Trust Remote Server Public Key',
			onRequestClose: onCancel,
			className: 'filechi-trust-key-modal',
			style: { maxWidth: 620 },
		},
			el('div', { style: { padding: '10px 0' } },
				el(Notice, {
					status: 'warning',
					isDismissible: false,
					style: { marginBottom: 16 },
				}, "The remote server's certificate is not trusted by the CA authorities (it may be self-signed or reached directly by IP address). Review the key details below and trust its public key once to pin it and allow secure connections."),

				el('table', { className: 'filechi-table', style: { marginTop: 0, marginBottom: 20 } },
					el('tbody', null,
						rows.map((row, idx) => el('tr', { key: idx },
							el('th', { style: { width: '32%', fontWeight: 600, color: '#1d2327' } }, row.label),
							el('td', { style: { wordBreak: 'break-all', fontFamily: row.isMono ? 'monospace' : 'inherit' } }, row.value || '—')
						))
					)
				),

				el('div', { style: { display: 'flex', justifyContent: 'flex-end', gap: 10 } },
					el(Button, {
						variant: 'secondary',
						onClick: (e) => {
							if (e && e.stopPropagation) e.stopPropagation();
							onCancel(e);
						},
					}, 'Cancel'),
					el(Button, {
						variant: 'primary',
						onClick: (e) => {
							if (e && e.stopPropagation) e.stopPropagation();
							onTrust(e);
						},
					}, 'Trust this key')
				)
			)
		);
	}

	const PROTECTED_FILE_INTEGRATIONS = ['woocommerce', 'edd'];

	/**
	 * Returns visible admin tabs based on active integrations.
	 *
	 * @param {Object} data Admin data object.
	 * @return {Array<{name: string, title: string}>}
	 */
	function visibleTabs(data) {
		const strings = (data && data.strings) || {};
		const allTabs = [
			{ name: 'connections', title: strings.connections || 'Connections' },
			{ name: 'media_rules', title: strings.mediaRules || 'Media Rules' },
			{ name: 'woocommerce', title: strings.woocommerce || 'WooCommerce' },
			{ name: 'migration_logs', title: strings.migrationLogs || 'Migration & Logs' },
		];

		let wcActive = false;
		if (data && Array.isArray(data.integrations)) {
			const wcIntegration = data.integrations.find((item) => item && item.id === 'woocommerce');
			if (wcIntegration) {
				wcActive = Boolean(wcIntegration.active);
			}
		} else if (data && data.woocommerce) {
			wcActive = Boolean(data.woocommerce.active);
		} else if (data && data.wc) {
			wcActive = Boolean(data.wc.active);
		}

		return allTabs.filter((tab) => {
			if (tab.name === 'woocommerce') {
				return wcActive;
			}
			return true;
		});
	}

	/**
	 * Determines whether the Protected Downloads Path field should be shown.
	 *
	 * @param {Object} data Admin data object.
	 * @return {boolean}
	 */
	function showProtectedPath(data) {
		if (!data) {
			return false;
		}
		if (Array.isArray(data.integrations)) {
			return data.integrations.some((item) =>
				item && PROTECTED_FILE_INTEGRATIONS.indexOf(item.id) !== -1 && Boolean(item.active)
			);
		}
		if (data.woocommerce && Boolean(data.woocommerce.active)) {
			return true;
		}
		if (data.wc && Boolean(data.wc.active)) {
			return true;
		}
		return false;
	}

	// Main App Component
	function FileChiAdminApp() {
		const adminData = (typeof window !== 'undefined' && window.filechiAdminData) ? window.filechiAdminData : {};
		const tabs = visibleTabs(adminData);
		const firstTabName = (tabs && tabs.length > 0) ? tabs[0].name : 'connections';

		function resolveInitialTab() {
			if (typeof window !== 'undefined' && window.location && window.location.hash) {
				const hash = window.location.hash.replace(/^#\/?/, '').trim();
				if (tabs.some((t) => t.name === hash)) {
					return hash;
				}
			}
			return firstTabName;
		}

		const [activeTab, setActiveTab] = useState(resolveInitialTab);
		const [providers, setProviders] = useState([]);
		const [settings, setSettings] = useState(null);
		const [stats, setStats] = useState(null);
		const [logs, setLogs] = useState({ items: [], total: 0, page: 1, pages: 1 });
		const [loading, setLoading] = useState(true);
		const [globalNotice, setGlobalNotice] = useState(null);

		const isCurrentTabVisible = tabs.some((t) => t.name === activeTab);
		const currentActiveTab = isCurrentTabVisible ? activeTab : firstTabName;

		useEffect(() => {
			if (!isCurrentTabVisible) {
				setActiveTab(firstTabName);
				if (typeof window !== 'undefined' && window.location && window.location.hash) {
					const hash = window.location.hash.replace(/^#\/?/, '').trim();
					if (hash === 'woocommerce') {
						window.location.hash = firstTabName;
					}
				}
			}
		}, [isCurrentTabVisible, firstTabName]);

		// Provider Modal State
		const [isModalOpen, setIsModalOpen] = useState(false);
		const [editingProvider, setEditingProvider] = useState(null);
		const [testResult, setTestResult] = useState(null);
		const [isTesting, setIsTesting] = useState(false);
		const [isSaving, setIsSaving] = useState(false);
		const [trustDialogData, setTrustDialogData] = useState(null);

		// Logs filter state
		const [logFilterStatus, setLogFilterStatus] = useState('');
		const [logSearch, setLogSearch] = useState('');
		const [logPage, setLogPage] = useState(1);

		// Load Providers
		const loadProviders = useCallback(async () => {
			try {
				const res = await apiFetch({ path: '/filechi/v1/providers' });
				setProviders(res || []);
			} catch (err) {
				setGlobalNotice({ type: 'error', message: err.message || 'Failed to load connection providers.' });
			}
		}, []);

		// Load Settings
		const loadSettings = useCallback(async () => {
			try {
				const res = await apiFetch({ path: '/filechi/v1/settings' });
				setSettings(res || {});
			} catch (err) {
				setGlobalNotice({ type: 'error', message: err.message || 'Failed to load settings.' });
			}
		}, []);

		// Load Stats
		const loadStats = useCallback(async () => {
			try {
				const res = await apiFetch({ path: '/filechi/v1/migration/stats' });
				setStats(res || {});
			} catch (err) {
				// Non-fatal
			}
		}, []);

		// Load Logs
		const loadLogs = useCallback(async (page = 1, status = '', search = '') => {
			try {
				const query = `limit=15&offset=${(page - 1) * 15}&status=${encodeURIComponent(status)}&search=${encodeURIComponent(search)}`;
				const res = await apiFetch({ path: `/filechi/v1/logs?${query}` });
				setLogs(res || { items: [], total: 0, page: 1, pages: 1 });
			} catch (err) {
				// Non-fatal
			}
		}, []);

		// Initial Data Fetch
		useEffect(() => {
			async function init() {
				setLoading(true);
				await Promise.all([loadProviders(), loadSettings(), loadStats(), loadLogs(1)]);
				setLoading(false);
			}
			init();
		}, [loadProviders, loadSettings, loadStats, loadLogs]);

		// Polling stats while migration is running
		useEffect(() => {
			if (stats && stats.status === 'running') {
				const timer = setInterval(() => {
					loadStats();
					loadLogs(logPage, logFilterStatus, logSearch);
				}, 3000);
				return () => clearInterval(timer);
			}
		}, [stats, logPage, logFilterStatus, logSearch, loadStats, loadLogs]);

		// Open Provider Modal (Create / Edit)
		function openProviderModal(provider = null) {
			setTestResult(null);
			setTrustDialogData(null);
			if (provider) {
				setEditingProvider({
					id: provider.id,
					name: provider.name,
					driver: provider.driver,
					is_default: provider.is_default,
					settings: Object.assign({}, provider.settings),
				});
			} else {
				setEditingProvider({
					name: '',
					driver: 'sftp',
					is_default: providers.length === 0 ? 1 : 0,
					settings: {
						host: '',
						port: 22,
						username: '',
						auth_type: 'password',
						password: '',
						private_key: '',
						passphrase: '',
						root_path: '',
						protected_path: '',
						base_url: '',
						timeout: 30,
						tls_pin: '',
						// S3 fields
						provider_type: 'custom',
						endpoint: '',
						region: 'us-east-1',
						bucket: '',
						access_key: '',
						secret_key: '',
						path_style: 1,
						public_acl: 1,
					},
				});
			}
			setIsModalOpen(true);
		}

		// Handle Provider Preset Change
		function handleS3PresetChange(preset) {
			const s = Object.assign({}, editingProvider.settings, { provider_type: preset });
			if (preset === 'arvancloud') {
				s.endpoint = 'https://s3.ir-thr-at1.arvanstorage.ir';
				s.region = 'ir-thr-at1';
				s.path_style = 1;
			} else if (preset === 'parspack') {
				s.region = 'default';
				s.path_style = 1;
				if (s.endpoint === 'https://s3.ir-thr-at1.arvanstorage.ir' || s.endpoint === 'https://s3.us-east-1.amazonaws.com') {
					s.endpoint = '';
				}
			} else if (preset === 'aws') {
				s.endpoint = 'https://s3.us-east-1.amazonaws.com';
				s.region = 'us-east-1';
				s.path_style = 0;
			}
			setEditingProvider(Object.assign({}, editingProvider, { settings: s }));
		}

		// Handle Driver Switch (SFTP default 22, FTPS default 21)
		function handleDriverChange(newDriver) {
			const s = Object.assign({}, editingProvider.settings);
			const currentPort = s.port;
			const isPortEmpty = currentPort === '' || currentPort === null || currentPort === undefined;
			const portNum = parseInt(currentPort, 10);

			if (newDriver === 'ftps') {
				if (isPortEmpty || portNum === 22) {
					s.port = 21;
				}
			} else if (newDriver === 'sftp') {
				if (isPortEmpty || portNum === 21) {
					s.port = 22;
				}
			}

			setEditingProvider(Object.assign({}, editingProvider, { driver: newDriver, settings: s }));
		}

		// Test Connection against unsaved form inputs
		async function handleTestConnection(customSettings = null, initialMessage = null) {
			setIsTesting(true);
			setTestResult({
				isTesting: true,
				message: initialMessage || 'Testing connection…',
			});

			const currentSettings = customSettings || (editingProvider ? editingProvider.settings : {});

			try {
				const res = await apiFetch({
					path: '/filechi/v1/test-connection',
					method: 'POST',
					data: {
						id: (editingProvider && editingProvider.id) || null,
						driver: (editingProvider && editingProvider.driver) || 'ftps',
						settings: currentSettings,
					},
				});

				if (res && res.needs_trust) {
					setTrustDialogData(res);
					setTestResult(null);
				} else {
					setTestResult(res);
				}
			} catch (err) {
				setTestResult({
					success: false,
					message: err.message || 'Connection test failed to respond.',
				});
			} finally {
				setIsTesting(false);
			}
		}

		// Trust Key Dialog handlers
		function handleTrustKey(e) {
			if (e && e.stopPropagation) {
				e.stopPropagation();
			}
			if (!trustDialogData || !trustDialogData.fingerprint) {
				setTrustDialogData(null);
				return;
			}
			const pin = trustDialogData.fingerprint;
			const currentProv = editingProvider || {};
			const updatedSettings = Object.assign({}, currentProv.settings || {}, { tls_pin: pin });
			const updatedProvider = Object.assign({}, currentProv, { settings: updatedSettings });

			// 1. Store the pin directly in editing state
			setEditingProvider(updatedProvider);

			// 2. Only trust dialog closes; Edit modal stays open
			setTrustDialogData(null);
			setIsModalOpen(true);

			// 3. Show intermediate notice inside Edit modal
			setTestResult({
				isTesting: true,
				message: 'Pin saved — testing connection…',
			});

			// 4. Use new value directly when re-running test without waiting for React state commit
			handleTestConnection(updatedSettings, 'Pin saved — testing connection…');
		}

		function handleCancelTrust(e) {
			if (e && e.stopPropagation) {
				e.stopPropagation();
			}
			setTrustDialogData(null);
			setIsModalOpen(true);
			setTestResult({
				success: false,
				message: 'Remote public key was not trusted. Connection refused.',
			});
		}

		// Save Provider
		async function handleSaveProvider() {
			if (!editingProvider.name.trim()) {
				alert('Please enter a name for this connection profile.');
				return;
			}

			if (editingProvider.driver === 'sftp' || editingProvider.driver === 'ftps') {
				const baseUrl = (editingProvider.settings && editingProvider.settings.base_url) ? String(editingProvider.settings.base_url).trim() : '';
				if (!baseUrl) {
					alert('Public URL Prefix is required for SFTP and FTPS connections.');
					return;
				}
			}

			setIsSaving(true);
			try {
				if (editingProvider.id) {
					await apiFetch({
						path: `/filechi/v1/providers/${editingProvider.id}`,
						method: 'PUT',
						data: editingProvider,
					});
				} else {
					await apiFetch({
						path: '/filechi/v1/providers',
						method: 'POST',
						data: editingProvider,
					});
				}

				setIsModalOpen(false);
				await loadProviders();
				setGlobalNotice({ type: 'success', message: 'Connection profile saved successfully.' });
			} catch (err) {
				alert(err.message || 'Failed to save provider.');
			} finally {
				setIsSaving(false);
			}
		}

		// Delete Provider
		async function handleDeleteProvider(id) {
			if (!confirm('Are you sure you want to delete this connection profile?')) {
				return;
			}

			try {
				await apiFetch({
					path: `/filechi/v1/providers/${id}`,
					method: 'DELETE',
				});
				await loadProviders();
				setGlobalNotice({ type: 'success', message: 'Connection profile deleted.' });
			} catch (err) {
				const errMsg = err.message || (err.data && err.data.message) || 'Failed to delete provider.';
				setGlobalNotice({ type: 'error', message: errMsg });
				alert(errMsg);
			}
		}

		// Set Default Provider
		async function handleSetDefaultProvider(id) {
			try {
				await apiFetch({
					path: `/filechi/v1/providers/${id}/set-default`,
					method: 'POST',
				});
				await loadProviders();
				setGlobalNotice({ type: 'success', message: 'Active default connection updated.' });
			} catch (err) {
				alert(err.message || 'Failed to set default provider.');
			}
		}

		// Save Media / General Settings
		async function handleSaveSettings() {
			try {
				await apiFetch({
					path: '/filechi/v1/settings',
					method: 'POST',
					data: settings,
				});
				setGlobalNotice({ type: 'success', message: 'Settings saved successfully.' });
			} catch (err) {
				alert(err.message || 'Failed to save settings.');
			}
		}

		// Migration Controls
		async function handleMigrationAction(action) {
			try {
				const res = await apiFetch({
					path: `/filechi/v1/migration/${action}`,
					method: 'POST',
				});
				setStats(res);
				loadLogs(1, logFilterStatus, logSearch);
			} catch (err) {
				alert(err.message || `Failed to ${action} migration.`);
			}
		}

		if (loading) {
			return el('div', { className: 'filechi-loading-state' },
				el(Spinner),
				' Loading FileChi Control Panel...'
			);
		}

		const adminVersion = (window.filechiAdminData && window.filechiAdminData.version) ? window.filechiAdminData.version : '';
		const versionLabel = adminVersion ? (adminVersion.startsWith('v') ? adminVersion : `v${adminVersion}`) : '';

		return el('div', { className: 'filechi-app-container' },
			// Header
			el('div', { className: 'filechi-header' },
				el('h1', null,
					'FileChi',
					versionLabel ? el('span', { className: 'filechi-version' }, versionLabel) : null
				),
				el('div', null,
					el(Button, {
						variant: 'primary',
						onClick: () => openProviderModal(),
					}, '+ Add Connection Profile')
				)
			),

			// Global Notice
			globalNotice && el(Notice, {
				status: globalNotice.type,
				isDismissible: true,
				onDismiss: () => setGlobalNotice(null),
				style: { marginBottom: 20 },
			}, globalNotice.message),

			// Tabs
			el(TabPanel, {
				className: 'filechi-tab-panel',
				activeClass: 'is-active',
				tabs: tabs,
				initialTabName: currentActiveTab,
				onSelect: (tabName) => {
					setActiveTab(tabName);
					if (typeof window !== 'undefined' && window.location) {
						window.location.hash = tabName;
					}
					setGlobalNotice(null);
					if (tabName === 'migration_logs') {
						loadStats();
						loadLogs(1, logFilterStatus, logSearch);
					}
				},
			}, (tab) => {
				switch (tab.name) {
					case 'connections':
						return renderConnectionsTab();
					case 'media_rules':
						return renderMediaRulesTab();
					case 'woocommerce':
						return renderWooCommerceTab();
					case 'migration_logs':
						return renderMigrationLogsTab();
					default:
						return null;
				}
			}),

			// Provider Edit/Create Modal
			isModalOpen && renderProviderModal(),

			// Trust Server Public Key Dialog (FTPS & SFTP)
			trustDialogData && el(TrustKeyDialog, {
				title: 'Trust FTPS Server Public Key',
				rows: [
					{ label: 'Subject', value: trustDialogData.subject },
					{ label: 'Issuer', value: trustDialogData.issuer },
					{ label: 'Algorithm', value: trustDialogData.algorithm },
					{ label: 'Fingerprint (Pin)', value: trustDialogData.fingerprint, isMono: true },
					{ label: 'Valid From', value: trustDialogData.valid_from },
					{ label: 'Valid To', value: trustDialogData.valid_to },
					...(trustDialogData.san ? [{ label: 'Alternative Names (SAN)', value: trustDialogData.san }] : []),
				],
				onTrust: handleTrustKey,
				onCancel: handleCancelTrust,
			})
		);

		// Render Tab 1: Connections
		function renderConnectionsTab() {
			if (providers.length === 0) {
				return el('div', { className: 'filechi-card', style: { textAlign: 'center', padding: '40px 20px' } },
					el('h3', null, 'No remote storage connections configured yet.'),
					el('p', { style: { color: '#646970', maxWidth: 500, margin: '10px auto 20px' } },
						'Configure your SFTP server, FTPS host, or S3-compatible bucket (AWS S3, ArvanCloud Object Storage, or ParsPack Object Storage) to begin offloading media attachments.'
					),
					el(Button, {
						variant: 'primary',
						onClick: () => openProviderModal(),
					}, 'Configure First Connection')
				);
			}

			return el('div', { className: 'filechi-provider-grid' },
				providers.map((p) => {
					return el('div', {
						key: p.id,
						className: `filechi-provider-box ${p.is_default ? 'is-default' : ''}`,
					},
						el('div', { className: 'filechi-provider-box-header' },
							el('h3', { className: 'filechi-provider-name' }, p.name),
							el('div', { style: { display: 'flex', gap: 6 } },
								el('span', { className: 'filechi-badge filechi-badge-driver' }, p.driver.toUpperCase()),
								p.is_default ? el('span', { className: 'filechi-badge filechi-badge-default' }, 'Default') : null
							)
						),
						el('div', { className: 'filechi-provider-meta' },
							p.driver === 's3' && el('div', null,
								el('strong', null, 'Bucket: '), p.settings.bucket || '—', el('br'),
								el('strong', null, 'Endpoint: '), p.settings.endpoint || '—'
							),
							(p.driver === 'sftp' || p.driver === 'ftps') && el('div', null,
								el('strong', null, 'Host: '), `${p.settings.host || '—'}:${p.settings.port || (p.driver === 'sftp' ? 22 : 21)}`, el('br'),
								el('strong', null, 'User: '), p.settings.username || '—'
							),
							p.settings && p.settings._decryption_failed ? el('div', {
								style: { marginTop: 10, padding: '8px 10px', background: '#fcf0f2', borderLeft: '4px solid #d63638', color: '#b32d2e', fontSize: 12, fontWeight: 500, borderRadius: 2 }
							}, "credentials can't be decrypted — re-enter them") : null
						),
						el('div', { className: 'filechi-provider-actions' },
							!p.is_default && el(Button, {
								isSmall: true,
								variant: 'secondary',
								onClick: () => handleSetDefaultProvider(p.id),
							}, 'Set as Default'),
							el(Button, {
								isSmall: true,
								variant: 'secondary',
								onClick: () => openProviderModal(p),
							}, 'Edit'),
							el(Button, {
								isSmall: true,
								isDestructive: true,
								variant: 'tertiary',
								onClick: () => handleDeleteProvider(p.id),
							}, 'Delete')
						)
					);
				})
			);
		}

		// Render Tab 2: Media Rules
		function renderMediaRulesTab() {
			if (!settings) return null;

			return el('div', { className: 'filechi-card' },
				el('h3', { className: 'filechi-card-title' }, 'Media Library Offload Rules'),
				el(ToggleControl, {
					label: 'Keep local file copies on WordPress server after remote upload',
					help: 'If enabled, files remain on local disk as well as remote storage. Default: enabled (safe default).',
					checked: !!settings.keep_local_files,
					onChange: (val) => setSettings(Object.assign({}, settings, { keep_local_files: val ? 1 : 0 })),
				}),
				!settings.keep_local_files && el(Notice, {
					status: 'warning',
					isDismissible: false,
					style: { margin: '10px 0 16px' }
				}, "Local copies will be deleted after upload. Plugins that need the local file (image editing, thumbnail regeneration, backups) won't work until you restore files, and uninstalling FileChi will leave your media unreachable unless you restore them first."),
				el(ToggleControl, {
					label: 'Keep remote file copy when media is permanently deleted from WordPress',
					help: 'If enabled, deleting an attachment in the Media Library will leave the remote storage file untouched.',
					checked: !!settings.keep_remote_on_delete,
					onChange: (val) => setSettings(Object.assign({}, settings, { keep_remote_on_delete: val ? 1 : 0 })),
				}),
				el(ToggleControl, {
					label: 'Transparently rewrite Media Library & srcset URLs to remote storage',
					help: 'Enables wp_get_attachment_url, wp_calculate_image_srcset, and editor filters to serve files directly from remote storage.',
					checked: !!settings.url_replacement,
					onChange: (val) => setSettings(Object.assign({}, settings, { url_replacement: val ? 1 : 0 })),
				}),
				el(TextControl, {
					label: 'Migration Batch Size',
					type: 'number',
					min: 1,
					max: 50,
					step: 1,
					help: 'Number of media files processed in each background Action Scheduler task (1 - 50).',
					value: settings.migration_batch_size || 10,
					onChange: (val) => setSettings(Object.assign({}, settings, { migration_batch_size: parseInt(val, 10) || 10 })),
					style: { maxWidth: 160 },
				}),
				el('div', { style: { borderTop: '1px solid #e0e0e0', paddingTop: 16, marginTop: 16 } },
					el(ToggleControl, {
						label: 'Delete all plugin data on uninstall',
						help: 'If enabled, uninstalling FileChi will permanently drop custom database tables, settings, logs, and attachment offload metadata. Remote storage files will NOT be deleted.',
						checked: !!settings.delete_data_on_uninstall,
						onChange: (val) => setSettings(Object.assign({}, settings, { delete_data_on_uninstall: val ? 1 : 0 })),
					}),
					!!settings.delete_data_on_uninstall && el(Notice, {
						status: 'warning',
						isDismissible: false,
						style: { margin: '10px 0 16px' }
					}, "Warning: If this option is enabled when you delete the plugin, your connection profiles, logs, and attachment tracking metadata will be permanently dropped. Remote storage files will remain untouched on your remote provider.")
				),
				el('div', { style: { marginTop: 24 } },
					el(Button, {
						variant: 'primary',
						onClick: handleSaveSettings,
					}, 'Save Changes')
				)
			);
		}

		// Render Tab 3: WooCommerce
		function renderWooCommerceTab() {
			if (!settings) return null;

			const wc = (window.filechiAdminData && window.filechiAdminData.woocommerce) || { active: false, version: '', hpos_enabled: false };
			const isWcActive = !!wc.active;

			const wcBoxStyle = isWcActive ? {
				display: 'flex',
				alignItems: 'center',
				gap: 12,
				background: '#ecfdf5',
				border: '1px solid #a7f3d0',
				padding: '12px 16px',
				borderRadius: 6,
				marginBottom: 20,
			} : {
				display: 'flex',
				alignItems: 'center',
				gap: 12,
				background: '#f6f7f7',
				border: '1px solid #dcdcde',
				padding: '12px 16px',
				borderRadius: 6,
				marginBottom: 20,
			};

			const wcIconClass = isWcActive ? 'dashicons dashicons-yes-alt' : 'dashicons dashicons-info';
			const wcIconColor = isWcActive ? '#047857' : '#646970';
			const wcTextColor = isWcActive ? '#047857' : '#50575e';
			const wcText = isWcActive
				? `WooCommerce ${wc.version ? wc.version + ' ' : ''}detected — HPOS ${wc.hpos_enabled ? 'enabled' : 'disabled'}. FileChi declares HPOS compatibility.`
				: 'WooCommerce not detected.';

			return el('div', { className: 'filechi-card' },
				el('h3', { className: 'filechi-card-title' }, 'WooCommerce Offloading & HPOS Integration'),

				el('div', { style: wcBoxStyle },
					el('span', { className: wcIconClass, style: { color: wcIconColor, fontSize: 22 } }),
					el('div', null,
						el('p', { style: { margin: 0, fontSize: 13, color: wcTextColor, fontWeight: 500 } },
							wcText
						)
					)
				),

				el(ToggleControl, {
					label: 'Generate signed, time-limited download URLs for WooCommerce downloadable products',
					help: 'When enabled, SFTP/FTPS downloads are streamed through your server; S3-compatible downloads use pre-signed URLs.',
					checked: !!settings.wc_signed_downloads,
					onChange: (val) => setSettings(Object.assign({}, settings, { wc_signed_downloads: val ? 1 : 0 })),
				}),

				el(TextControl, {
					label: 'Signed URL Expiration Time (seconds)',
					type: 'number',
					help: 'How long the signed download link remains valid (e.g. 900 seconds = 15 minutes).',
					value: settings.wc_download_expiry || 900,
					onChange: (val) => setSettings(Object.assign({}, settings, { wc_download_expiry: parseInt(val, 10) || 900 })),
					style: { maxWidth: 200 },
				}),

				el('div', { style: { marginTop: 24 } },
					el(Button, {
						variant: 'primary',
						onClick: handleSaveSettings,
					}, 'Save Changes')
				)
			);
		}

		// Render Tab 4: Migration & Logs
		function renderMigrationLogsTab() {
			if (!stats) return null;

			const percent = stats.total > 0 ? Math.round((stats.offloaded / stats.total) * 100) : 100;
			const failedCount = stats.failed || 0;
			const givenUpCount = stats.given_up || 0;
			const hasAttention = failedCount > 0 || givenUpCount > 0;

			return el('div', null,
				// Migration Dashboard Card
				el('div', { className: 'filechi-card' },
					el('h3', { className: 'filechi-card-title' }, 'Bulk Media Library Migration'),

					el('div', { className: 'filechi-stats-grid' },
						el('div', { className: 'filechi-stat-card' },
							el('div', { className: 'filechi-stat-value' }, stats.total),
							el('div', { className: 'filechi-stat-label' }, 'Total Media Items')
						),
						el('div', { className: 'filechi-stat-card' },
							el('div', { className: 'filechi-stat-value', style: { color: '#047857' } }, stats.offloaded),
							el('div', { className: 'filechi-stat-label' }, 'Offloaded to Remote')
						),
						el('div', { className: 'filechi-stat-card' },
							el('div', { className: 'filechi-stat-value', style: { color: '#b45309' } }, stats.remaining),
							el('div', { className: 'filechi-stat-label' }, 'Remaining to Offload')
						),
						el('div', { className: `filechi-stat-card${hasAttention ? ' has-attention' : ''}` },
							el('div', {
								className: 'filechi-stat-value',
								style: hasAttention ? { color: '#b91c1c' } : undefined,
							}, failedCount),
							el('div', { className: 'filechi-stat-label' }, 'Needs Attention'),
							el('div', {
								className: 'filechi-stat-sublabel',
								style: hasAttention ? { color: '#b91c1c' } : undefined,
							}, `${givenUpCount} given up`)
						),
						el('div', { className: 'filechi-stat-card' },
							el('div', { className: 'filechi-stat-value' }, formatBytes(stats.total_bytes)),
							el('div', { className: 'filechi-stat-label' }, 'Remote Space Used')
						)
					),

					// Progress Bar
					el('div', { className: 'filechi-progress-wrapper' },
						el('div', { className: 'filechi-progress-bar' },
							el('div', {
								className: 'filechi-progress-fill',
								style: { width: `${percent}%` },
							})
						),
						el('div', { className: 'filechi-progress-label' },
							el('span', null, `Status: ${stats.status.toUpperCase()} (${percent}% complete)`),
							el('span', null, `${stats.offloaded} of ${stats.total} attachments offloaded`)
						)
					),

					// Controls
					el('div', { style: { display: 'flex', gap: 10, marginTop: 16 } },
						stats.status !== 'running' && el(Button, {
							variant: 'primary',
							onClick: () => handleMigrationAction('start'),
						}, stats.status === 'paused' ? 'Resume Migration' : 'Start Bulk Migration'),

						stats.status === 'running' && el(Button, {
							variant: 'secondary',
							onClick: () => handleMigrationAction('pause'),
						}, 'Pause Migration'),

						(stats.failed > 0 || (stats.given_up && stats.given_up > 0)) && el(Button, {
							variant: 'secondary',
							onClick: () => handleMigrationAction('retry'),
						}, 'Retry Failed Items')
					)
				),

				// Transfer Logs Card
				el('div', { className: 'filechi-card' },
					el('div', { style: { display: 'flex', justifyContent: 'space-between', alignItems: 'center' } },
						el('h3', { className: 'filechi-card-title', style: { border: 'none', margin: 0 } }, 'Transfer Activity Logs'),
						el('div', { style: { display: 'flex', gap: 10 } },
							el(SelectControl, {
								value: logFilterStatus,
								options: [
									{ label: 'All Statuses', value: '' },
									{ label: 'Transferred', value: 'transferred' },
									{ label: 'Reconciled', value: 'reconciled' },
									{ label: 'Failed', value: 'failed' },
									{ label: 'Pending', value: 'pending' },
									{ label: 'Deleted', value: 'deleted' },
								],
								onChange: (val) => {
									setLogFilterStatus(val);
									setLogPage(1);
									loadLogs(1, val, logSearch);
								},
							}),
							el(Button, {
								variant: 'secondary',
								onClick: () => loadLogs(logPage, logFilterStatus, logSearch),
							}, 'Refresh Logs')
						)
					),

					logs.items.length === 0 ? el('p', { style: { color: '#646970', padding: '20px 0' } }, 'No transfer log entries found.')
						: el('table', { className: 'filechi-table' },
							el('thead', null,
								el('tr', null,
									el('th', null, 'Attachment ID'),
									el('th', null, 'File Path'),
									el('th', null, 'Size'),
									el('th', null, 'Status'),
									el('th', null, 'Attempts'),
									el('th', null, 'Date'),
									el('th', null, 'Details')
								)
							),
							el('tbody', null,
								logs.items.map((log) => {
									return el('tr', { key: log.id },
										el('td', null, log.attachment_id),
										el('td', { style: { wordBreak: 'break-all' } }, log.file_path),
										el('td', null, formatBytes(log.file_size)),
										el('td', null, el('span', { className: `status-badge ${log.status}` }, log.status.toUpperCase())),
										el('td', null, log.attempts),
										el('td', null, log.updated_at),
										el('td', { style: { color: log.status === 'failed' ? '#c5221f' : '#646970' } }, log.error_message || '—')
									);
								})
							)
						),

					// Pagination
					logs.pages > 1 && el('div', { style: { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: 16 } },
						el('span', { style: { fontSize: 13, color: '#646970' } }, `Page ${logs.page} of ${logs.pages} (${logs.total} total items)`),
						el('div', { style: { display: 'flex', gap: 6 } },
							el(Button, {
								isSmall: true,
								disabled: logs.page <= 1,
								onClick: () => {
									const p = logs.page - 1;
									setLogPage(p);
									loadLogs(p, logFilterStatus, logSearch);
								},
							}, 'Previous'),
							el(Button, {
								isSmall: true,
								disabled: logs.page >= logs.pages,
								onClick: () => {
									const p = logs.page + 1;
									setLogPage(p);
									loadLogs(p, logFilterStatus, logSearch);
								},
							}, 'Next')
						)
					)
				)
			);
		}

		// Render Provider Modal
		function renderProviderModal() {
			const s = editingProvider.settings;

			return el(Modal, {
				title: editingProvider.id ? 'Edit Connection Profile' : 'Add Connection Profile',
				onRequestClose: () => setIsModalOpen(false),
				style: { maxWidth: 640 },
			},
				el('div', { style: { padding: '10px 0' } },
					!!(editingProvider && editingProvider.settings && editingProvider.settings._decryption_failed) && el(Notice, {
						status: 'error',
						isDismissible: false,
						style: { marginBottom: 15 }
					}, "credentials can't be decrypted — re-enter them"),

					el(TextControl, {
						label: 'Profile Name',
						value: editingProvider.name,
						onChange: (val) => setEditingProvider(Object.assign({}, editingProvider, { name: val })),
						placeholder: 'e.g. ArvanCloud Simin Bucket or StorageBox SFTP',
					}),

					el(SelectControl, {
						label: 'Storage Protocol / Driver',
						value: editingProvider.driver,
						options: [
							{ label: 'SFTP (SSH File Transfer Protocol - Recommended)', value: 'sftp' },
							{ label: 'S3-Compatible Object Storage (AWS / ArvanCloud / ParsPack)', value: 's3' },
							{ label: 'FTPS (FTP over Explicit TLS)', value: 'ftps' },
						],
						onChange: handleDriverChange,
					}),

					// Driver Specific Fields: S3
					editingProvider.driver === 's3' && el('div', { style: { borderTop: '1px solid #e0e0e0', paddingTop: 16, marginTop: 16 } },
						el(SelectControl, {
							label: 'Provider Preset',
							value: s.provider_type || 'custom',
							options: [
								{ label: 'ArvanCloud Object Storage (Iran)', value: 'arvancloud' },
								{ label: 'ParsPack Object Storage (Iran)', value: 'parspack' },
								{ label: 'Amazon Web Services (AWS S3)', value: 'aws' },
								{ label: 'Custom S3-Compatible (MinIO, Ceph, Cloudflare R2)', value: 'custom' },
							],
							onChange: handleS3PresetChange,
						}),
						el(TextControl, {
							label: 'Endpoint URL',
							value: s.endpoint || '',
							onChange: (val) => updateSettingField('endpoint', val),
							placeholder: s.provider_type === 'parspack' ? 'https://...' : 'https://s3.ir-thr-at1.arvanstorage.ir',
							help: s.provider_type === 'parspack'
								? 'ParsPack does not publish a fixed endpoint. Copy the S3 endpoint from your ParsPack panel into the field above.'
								: 'Full S3 REST API endpoint including https://',
						}),
						el(TextControl, {
							label: 'Bucket Name',
							value: s.bucket || '',
							onChange: (val) => updateSettingField('bucket', val),
							placeholder: 'my-site-media',
						}),
						el(TextControl, {
							label: 'Region',
							value: s.region || 'us-east-1',
							onChange: (val) => updateSettingField('region', val),
							placeholder: 'ir-thr-at1, us-east-1, or default',
						}),
						el(TextControl, {
							label: 'Access Key ID',
							value: s.access_key || '',
							onChange: (val) => updateSettingField('access_key', val),
						}),
						el(TextControl, {
							label: 'Secret Access Key',
							type: 'password',
							value: s.secret_key || '',
							onChange: (val) => updateSettingField('secret_key', val),
							placeholder: editingProvider.id ? '********' : '',
						}),
						el(ToggleControl, {
							label: 'Use Path-Style Addressing (https://endpoint/bucket/key)',
							help: 'Required for ArvanCloud, ParsPack, and MinIO. Uncheck for AWS S3 virtual-hosted.',
							checked: s.path_style !== 0,
							onChange: (val) => updateSettingField('path_style', val ? 1 : 0),
						}),
						el(ToggleControl, {
							label: 'Set public-read ACL on uploaded objects',
							help: 'Allows direct HTTP browser requests to view uploaded images and documents.',
							checked: s.public_acl !== 0,
							onChange: (val) => updateSettingField('public_acl', val ? 1 : 0),
						})
					),

					// Driver Specific Fields: SFTP
					editingProvider.driver === 'sftp' && el('div', { style: { borderTop: '1px solid #e0e0e0', paddingTop: 16, marginTop: 16 } },
						el(TextControl, {
							label: 'SFTP Host / IP',
							value: s.host || '',
							onChange: (val) => updateSettingField('host', val),
							placeholder: 'sftp.yourserver.com',
						}),
						el(TextControl, {
							label: 'Port',
							type: 'number',
							value: s.port || 22,
							onChange: (val) => updateSettingField('port', val),
						}),
						el(TextControl, {
							label: 'Username',
							value: s.username || '',
							onChange: (val) => updateSettingField('username', val),
						}),
						el(SelectControl, {
							label: 'Authentication Method',
							value: s.auth_type || 'password',
							options: [
								{ label: 'Password', value: 'password' },
								{ label: 'SSH Private Key (RSA / Ed25519)', value: 'key' },
							],
							onChange: (val) => updateSettingField('auth_type', val),
						}),
						s.auth_type !== 'key' ? el(TextControl, {
							label: 'Password',
							type: 'password',
							value: s.password || '',
							onChange: (val) => updateSettingField('password', val),
							placeholder: editingProvider.id ? '********' : '',
						}) : el('div', null,
							el('label', { style: { display: 'block', fontWeight: 600, fontSize: 13, marginBottom: 4 } }, 'Private Key (PEM format)'),
							el('textarea', {
								className: 'regular-text',
								rows: 5,
								style: { width: '100%', fontFamily: 'monospace' },
								value: s.private_key || '',
								onChange: (e) => updateSettingField('private_key', e.target.value),
								placeholder: '-----BEGIN OPENSSH PRIVATE KEY-----...',
							}),
							el(TextControl, {
								label: 'Key Passphrase (optional)',
								type: 'password',
								value: s.passphrase || '',
								onChange: (val) => updateSettingField('passphrase', val),
							})
						),
						el(TextControl, {
							label: 'Remote Base Path (optional)',
							value: s.root_path || '',
							onChange: (val) => updateSettingField('root_path', val),
							placeholder: '/var/www/uploads or public_html/wp-files',
							help: "Absolute (/home/user/files) or relative to the login directory. Do not use '..'.",
						}),
						showProtectedPath(window.filechiAdminData) && el(TextControl, {
							label: 'Protected Downloads Path (optional, outside web root)',
							value: s.protected_path || '',
							onChange: (val) => updateSettingField('protected_path', val),
							placeholder: '/home/secure/protected_downloads',
							help: 'Separate absolute directory outside public web root for WooCommerce protected files.',
						})
					),

					// Driver Specific Fields: FTPS
					editingProvider.driver === 'ftps' && el('div', { style: { borderTop: '1px solid #e0e0e0', paddingTop: 16, marginTop: 16 } },
						el(Notice, {
							status: 'warning',
							isDismissible: false,
							style: { marginBottom: 15 },
						}, "FTPS verifies the server certificate. For a server reached by IP address or with a self-signed certificate, trust its public key once: it is pinned and any later change is refused."),
						el(TextControl, {
							label: 'FTPS Host / IP',
							value: s.host || '',
							onChange: (val) => updateSettingField('host', val),
							placeholder: 'ftp.yourserver.com',
						}),
						el(TextControl, {
							label: 'Port',
							type: 'number',
							value: s.port || 21,
							onChange: (val) => updateSettingField('port', val),
						}),
						!!s.tls_pin && el('div', {
							className: 'filechi-pin-box',
							style: { background: '#f6f7f7', border: '1px solid #dcdcde', borderRadius: 4, padding: '10px 14px', marginBottom: 16 },
						},
							el('label', { style: { display: 'block', fontWeight: 600, fontSize: 13, marginBottom: 4 } }, 'Pinned Public Key Fingerprint (TLS Pin)'),
							el('code', { style: { display: 'block', wordBreak: 'break-all', fontSize: 12, marginBottom: 8, color: '#0969da' } }, s.tls_pin),
							el(Button, {
								isDestructive: true,
								isSmall: true,
								onClick: () => {
									updateSettingField('tls_pin', '');
									setTestResult(null);
								},
							}, 'Reset trusted key')
						),
						el(TextControl, {
							label: 'Username',
							value: s.username || '',
							onChange: (val) => updateSettingField('username', val),
						}),
						el(TextControl, {
							label: 'Password',
							type: 'password',
							value: s.password || '',
							onChange: (val) => updateSettingField('password', val),
							placeholder: editingProvider.id ? '********' : '',
						}),
						el(ToggleControl, {
							label: 'Passive Mode (Recommended)',
							checked: s.passive !== 0,
							onChange: (val) => updateSettingField('passive', val ? 1 : 0),
						}),
						el(TextControl, {
							label: 'Remote Base Path (optional)',
							value: s.root_path || '',
							onChange: (val) => updateSettingField('root_path', val),
							placeholder: 'public_html/uploads',
							help: "Absolute (/home/user/files) or relative to the login directory. Do not use '..'.",
						}),
						showProtectedPath(window.filechiAdminData) && el(TextControl, {
							label: 'Protected Downloads Path (optional, outside web root)',
							value: s.protected_path || '',
							onChange: (val) => updateSettingField('protected_path', val),
							placeholder: 'protected_downloads',
							help: 'Separate directory outside public web root for WooCommerce protected files.',
						})
					),

					// Common: Custom CDN / URL prefix
					el(TextControl, {
						label: (editingProvider.driver === 'sftp' || editingProvider.driver === 'ftps')
							? 'Public URL Prefix / CDN Domain (required)'
							: 'Public URL Prefix / CDN Domain (optional)',
						value: s.base_url || '',
						onChange: (val) => updateSettingField('base_url', val),
						placeholder: 'https://media.mysite.com',
						help: (editingProvider.driver === 'sftp' || editingProvider.driver === 'ftps')
							? 'Required: Public HTTP(S) URL prefix where files offloaded via SFTP/FTPS are accessible.'
							: 'Custom domain or CDN endpoint mapping to this storage location.',
					}),

					// Test Result Notice
					testResult && (() => {
						if (testResult.isTesting) {
							return el('div', {
								className: 'filechi-test-result is-testing',
								style: {
									background: '#f0f6fc',
									borderLeft: '4px solid #0969da',
									color: '#0969da',
								},
							},
								el('span', { className: 'dashicons dashicons-update', style: { marginRight: 8, verticalAlign: 'text-bottom' } }),
								testResult.message || 'Testing connection…'
							);
						}

						const isMismatch = (
							(testResult.message && testResult.message.indexOf('PINNED KEY MISMATCH') !== -1) ||
							(testResult.details && testResult.details.curl_errno === 90)
						);
						return el('div', {
							className: `filechi-test-result ${testResult.success ? 'success' : 'error'}${isMismatch ? ' is-mismatch' : ''}`,
							style: isMismatch ? {
								background: '#fef2f2',
								borderLeft: '4px solid #dc2626',
								color: '#991b1b',
								fontWeight: 600,
							} : undefined,
						},
							isMismatch && el('span', { className: 'dashicons dashicons-warning', style: { marginRight: 8, color: '#dc2626', verticalAlign: 'text-bottom' } }),
							el('div', { style: { marginBottom: (testResult.stages && testResult.stages.length) ? 8 : 0 } }, testResult.message),
							(testResult.stages && Array.isArray(testResult.stages)) && el('ul', {
								className: 'filechi-stage-checklist',
							},
								testResult.stages.map((stg, sIdx) => el('li', {
									key: sIdx,
									className: `filechi-stage-item ${stg.ok ? 'is-ok' : 'is-fail'}`,
								},
									el('span', { style: { fontWeight: 'bold', width: 14 } }, stg.ok ? '✓' : '✗'),
									el('strong', null, stg.name + ':'),
									el('span', { style: { color: '#374151', marginLeft: 4 } }, stg.detail)
								))
							)
						);
					})(),

					// Modal Actions
					el('div', { style: { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: 24 } },
						el(Button, {
							variant: 'secondary',
							isBusy: isTesting,
							disabled: isTesting,
							onClick: () => handleTestConnection(),
						}, isTesting ? 'Testing Connection...' : '⚡ Test Connection'),

						el('div', { style: { display: 'flex', gap: 10 } },
							el(Button, {
								variant: 'tertiary',
								onClick: () => setIsModalOpen(false),
							}, 'Cancel'),
							el(Button, {
								variant: 'primary',
								isBusy: isSaving,
								disabled: isSaving,
								onClick: handleSaveProvider,
							}, isSaving ? 'Saving...' : 'Save Profile')
						)
					)
				)
			);
		}

		function updateSettingField(key, val) {
			const s = Object.assign({}, editingProvider.settings, { [key]: val });
			if ((key === 'host' || key === 'port') && editingProvider && editingProvider.settings && editingProvider.settings.tls_pin) {
				s.tls_pin = '';
			}
			setEditingProvider(Object.assign({}, editingProvider, { settings: s }));
		}
	}

	// Mount Component when DOM is ready
	if (typeof document !== 'undefined') {
		document.addEventListener('DOMContentLoaded', function () {
			const root = document.getElementById('filechi-admin-app');
			if (root && wp && wp.element) {
				wp.element.render(el(FileChiAdminApp), root);
			}
		});
	}

	if (typeof module !== 'undefined' && module.exports) {
		module.exports = {
			visibleTabs,
			showProtectedPath,
		};
	}

})(typeof window !== 'undefined' && window.wp ? window.wp : (typeof wp !== 'undefined' ? wp : {}));
