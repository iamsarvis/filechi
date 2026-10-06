/**
 * FileChi Admin Application
 * Built with @wordpress/element, @wordpress/components, and @wordpress/api-fetch
 */

(function (wp) {
	'use strict';

	const { createElement: el, useState, useEffect, useCallback } = wp.element;
	const {
		TabPanel,
		Button,
		TextControl,
		SelectControl,
		ToggleControl,
		Notice,
		Spinner,
		Modal,
	} = wp.components;
	const apiFetch = wp.apiFetch;

	function formatBytes(bytes) {
		if (!bytes || bytes === 0) return '0 B';
		const k = 1024;
		const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
		const i = Math.floor(Math.log(bytes) / Math.log(k));
		return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
	}

	// Main App Component
	function FileChiAdminApp() {
		const [activeTab, setActiveTab] = useState('connections');
		const [providers, setProviders] = useState([]);
		const [settings, setSettings] = useState(null);
		const [stats, setStats] = useState(null);
		const [logs, setLogs] = useState({ items: [], total: 0, page: 1, pages: 1 });
		const [loading, setLoading] = useState(true);
		const [globalNotice, setGlobalNotice] = useState(null);

		// Provider Modal State
		const [isModalOpen, setIsModalOpen] = useState(false);
		const [editingProvider, setEditingProvider] = useState(null);
		const [testResult, setTestResult] = useState(null);
		const [isTesting, setIsTesting] = useState(false);
		const [isSaving, setIsSaving] = useState(false);

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
						base_url: '',
						timeout: 30,
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
			} else if (preset === 'aws') {
				s.endpoint = 'https://s3.us-east-1.amazonaws.com';
				s.region = 'us-east-1';
				s.path_style = 0;
			}
			setEditingProvider(Object.assign({}, editingProvider, { settings: s }));
		}

		// Test Connection against unsaved form inputs
		async function handleTestConnection() {
			setIsTesting(true);
			setTestResult(null);

			try {
				const res = await apiFetch({
					path: '/filechi/v1/test-connection',
					method: 'POST',
					data: {
						id: editingProvider.id || null,
						driver: editingProvider.driver,
						settings: editingProvider.settings,
					},
				});

				setTestResult(res);
			} catch (err) {
				setTestResult({
					success: false,
					message: err.message || 'Connection test failed to respond.',
				});
			} finally {
				setIsTesting(false);
			}
		}

		// Save Provider
		async function handleSaveProvider() {
			if (!editingProvider.name.trim()) {
				alert('Please enter a name for this connection profile.');
				return;
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
				alert(err.message || 'Failed to delete provider.');
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

		// Tab definitions
		const tabs = [
			{ name: 'connections', title: 'Connections' },
			{ name: 'media_rules', title: 'Media Rules' },
			{ name: 'woocommerce', title: 'WooCommerce' },
			{ name: 'migration_logs', title: 'Migration & Logs' },
		];

		return el('div', { className: 'filechi-app-container' },
			// Header
			el('div', { className: 'filechi-header' },
				el('h1', null,
					'FileChi',
					el('span', { className: 'filechi-version' }, 'v1.0.0')
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
				initialTabName: activeTab,
				onSelect: (tabName) => {
					setActiveTab(tabName);
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
			isModalOpen && renderProviderModal()
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
					help: 'If enabled, files remain on local disk as well as remote storage. Default: disabled (saves web server disk space).',
					checked: !!settings.keep_local_files,
					onChange: (val) => setSettings(Object.assign({}, settings, { keep_local_files: val ? 1 : 0 })),
				}),
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
					help: 'Number of media files processed in each background Action Scheduler task (1 - 50).',
					value: settings.migration_batch_size || 10,
					onChange: (val) => setSettings(Object.assign({}, settings, { migration_batch_size: parseInt(val, 10) || 10 })),
					style: { maxWidth: 160 },
				}),
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

			return el('div', { className: 'filechi-card' },
				el('h3', { className: 'filechi-card-title' }, 'WooCommerce Offloading & HPOS Integration'),

				el('div', {
					style: {
						display: 'flex',
						alignItems: 'center',
						gap: 12,
						background: '#ecfdf5',
						border: '1px solid #a7f3d0',
						padding: '12px 16px',
						borderRadius: 6,
						marginBottom: 20,
					},
				},
					el('span', { className: 'dashicons dashicons-yes-alt', style: { color: '#047857', fontSize: 22 } }),
					el('div', null,
						el('strong', { style: { color: '#065f46' } }, 'High-Performance Order Storage (HPOS) Compatibility Declared'),
						el('p', { style: { margin: 0, fontSize: 13, color: '#047857' } },
							'FileChi officially declares and verifies compatibility with WooCommerce 10.x & 11.x custom order tables.'
						)
					)
				),

				el(ToggleControl, {
					label: 'Generate signed, time-limited download URLs for WooCommerce downloadable products',
					help: 'When enabled, download requests are served with AWS SigV4 signed time-limited URLs or secure tokenized streaming redirects rather than exposing permanent remote links.',
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
						el('div', { className: 'filechi-stat-card' },
							el('div', { className: 'filechi-stat-value', style: { color: '#b91c1c' } }, stats.failed),
							el('div', { className: 'filechi-stat-label' }, 'Failed Transfers')
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

						stats.failed > 0 && el(Button, {
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
					editingProvider && editingProvider.settings && editingProvider.settings._decryption_failed && el(Notice, {
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
						onChange: (val) => setEditingProvider(Object.assign({}, editingProvider, { driver: val })),
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
							placeholder: 'https://s3.ir-thr-at1.arvanstorage.ir',
							help: 'Full S3 REST API endpoint including https://',
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
						})
					),

					// Driver Specific Fields: FTPS
					editingProvider.driver === 'ftps' && el('div', { style: { borderTop: '1px solid #e0e0e0', paddingTop: 16, marginTop: 16 } },
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
						})
					),

					// Common: Custom CDN / URL prefix
					el(TextControl, {
						label: 'Public URL Prefix / CDN Domain (optional)',
						value: s.base_url || '',
						onChange: (val) => updateSettingField('base_url', val),
						placeholder: 'https://media.mysite.com',
						help: 'Custom domain or CDN endpoint mapping to this storage location.',
					}),

					// Test Result Notice
					testResult && el('div', {
						className: `filechi-test-result ${testResult.success ? 'success' : 'error'}`,
					}, testResult.message),

					// Modal Actions
					el('div', { style: { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: 24 } },
						el(Button, {
							variant: 'secondary',
							isBusy: isTesting,
							disabled: isTesting,
							onClick: handleTestConnection,
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
			setEditingProvider(Object.assign({}, editingProvider, { settings: s }));
		}
	}

	// Mount Component when DOM is ready
	document.addEventListener('DOMContentLoaded', function () {
		const root = document.getElementById('filechi-admin-app');
		if (root) {
			wp.element.render(el(FileChiAdminApp), root);
		}
	});

})(window.wp);
