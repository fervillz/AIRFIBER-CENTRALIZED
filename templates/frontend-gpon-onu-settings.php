<?php defined( 'ABSPATH' ) || exit; ?>
<div class="afc-gpon-modal" data-afc-gpon-modal hidden>
	<div class="afc-gpon-backdrop" data-afc-gpon-close></div>
	<section class="afc-gpon-dialog" role="dialog" aria-modal="true" aria-labelledby="afc-gpon-title">
		<header class="afc-gpon-header">
			<div>
				<span class="afc-gpon-eyebrow"><?php esc_html_e( 'GPON subscriber', 'airfiber-centralized' ); ?></span>
				<h2 id="afc-gpon-title"><?php esc_html_e( 'ONU Settings', 'airfiber-centralized' ); ?></h2>
				<p data-afc-gpon-customer></p>
			</div>
			<button type="button" class="afc-gpon-close" data-afc-gpon-close aria-label="<?php esc_attr_e( 'Close ONU Settings', 'airfiber-centralized' ); ?>">×</button>
		</header>

		<div class="afc-gpon-status is-neutral" data-afc-gpon-status role="status" aria-live="polite">
			<span></span><p><?php esc_html_e( 'Choose an action below.', 'airfiber-centralized' ); ?></p>
		</div>

		<nav class="afc-gpon-tabs" aria-label="<?php esc_attr_e( 'ONU Settings sections', 'airfiber-centralized' ); ?>">
			<button type="button" data-afc-gpon-tab="overview" class="is-active"><?php esc_html_e( 'Overview', 'airfiber-centralized' ); ?></button>
			<button type="button" data-afc-gpon-tab="line"><?php esc_html_e( 'Line & VLANs', 'airfiber-centralized' ); ?></button>
			<button type="button" data-afc-gpon-tab="tr069"><?php esc_html_e( 'TR-069', 'airfiber-centralized' ); ?></button>
			<button type="button" data-afc-gpon-tab="provision"><?php esc_html_e( 'Prepare GPON', 'airfiber-centralized' ); ?></button>
		</nav>

		<div class="afc-gpon-body">
			<section data-afc-gpon-panel="overview" class="is-active">
				<div class="afc-gpon-summary" data-afc-gpon-summary>
					<div><span><?php esc_html_e( 'OLT', 'airfiber-centralized' ); ?></span><strong data-afc-gpon-summary-olt>—</strong></div>
					<div><span><?php esc_html_e( 'PON / ONU', 'airfiber-centralized' ); ?></span><strong data-afc-gpon-summary-location>—</strong></div>
					<div><span><?php esc_html_e( 'Serial', 'airfiber-centralized' ); ?></span><strong data-afc-gpon-summary-serial>—</strong></div>
					<div><span><?php esc_html_e( 'RX power', 'airfiber-centralized' ); ?></span><strong data-afc-gpon-summary-rx>—</strong></div>
				</div>
				<div class="afc-gpon-data-card">
					<div class="afc-gpon-card-title"><h3><?php esc_html_e( 'ONU identity and state', 'airfiber-centralized' ); ?></h3><button type="button" data-afc-gpon-refresh><?php esc_html_e( 'Refresh', 'airfiber-centralized' ); ?></button></div>
					<div class="afc-gpon-key-values" data-afc-gpon-overview></div>
				</div>
			</section>

			<section data-afc-gpon-panel="line">
				<div class="afc-gpon-line-grid">
					<div class="afc-gpon-data-card"><h3><?php esc_html_e( 'TCONT', 'airfiber-centralized' ); ?></h3><div data-afc-gpon-table="tconts"></div></div>
					<div class="afc-gpon-data-card"><h3><?php esc_html_e( 'GEM ports', 'airfiber-centralized' ); ?></h3><div data-afc-gpon-table="gemports"></div></div>
					<div class="afc-gpon-data-card"><h3><?php esc_html_e( 'Services', 'airfiber-centralized' ); ?></h3><div data-afc-gpon-table="services"></div></div>
					<div class="afc-gpon-data-card"><h3><?php esc_html_e( 'Service ports / VLAN translation', 'airfiber-centralized' ); ?></h3><div data-afc-gpon-table="service_ports"></div></div>
				</div>
			</section>

			<section data-afc-gpon-panel="tr069">
				<div class="afc-gpon-data-card">
					<div class="afc-gpon-card-title"><div><h3><?php esc_html_e( 'TR-069 status', 'airfiber-centralized' ); ?></h3><p><?php esc_html_e( 'Passwords and other secrets are always masked.', 'airfiber-centralized' ); ?></p></div></div>
					<div class="afc-gpon-key-values" data-afc-gpon-tr069></div>
				</div>
			</section>

			<section data-afc-gpon-panel="provision">
				<div class="afc-gpon-provision-layout">
					<form data-afc-gpon-form autocomplete="off">
						<input type="hidden" name="customer_id" value="0">
						<div class="afc-gpon-field is-wide">
							<label for="afc-gpon-olt"><?php esc_html_e( 'GPON OLT', 'airfiber-centralized' ); ?></label>
							<select id="afc-gpon-olt" name="olt_id" required></select>
						</div>
						<div class="afc-gpon-field">
							<label for="afc-gpon-pon"><?php esc_html_e( 'PON', 'airfiber-centralized' ); ?></label>
							<input id="afc-gpon-pon" name="pon" type="number" min="1" max="128" required>
						</div>
						<div class="afc-gpon-field">
							<label for="afc-gpon-onu"><?php esc_html_e( 'ONU ID', 'airfiber-centralized' ); ?></label>
							<input id="afc-gpon-onu" name="onu" type="number" min="1" max="128" placeholder="<?php esc_attr_e( 'Auto', 'airfiber-centralized' ); ?>">
						</div>
						<div class="afc-gpon-field is-wide">
							<label for="afc-gpon-serial"><?php esc_html_e( 'ONU serial number', 'airfiber-centralized' ); ?></label>
							<input id="afc-gpon-serial" name="serial" type="text" inputmode="text" placeholder="FHTT97c9ecc0" required>
							<small><?php esc_html_e( 'Airfiber formats the first four characters uppercase and the rest lowercase.', 'airfiber-centralized' ); ?></small>
						</div>
						<div class="afc-gpon-field is-wide">
							<label for="afc-gpon-vlan-template"><?php esc_html_e( 'VLAN template', 'airfiber-centralized' ); ?></label>
							<select id="afc-gpon-vlan-template" data-afc-gpon-vlan-template>
								<option value="510,399">510 + 399</option>
								<option value="499,434">499 + 434</option>
								<option value="custom"><?php esc_html_e( 'Custom VLANs', 'airfiber-centralized' ); ?></option>
							</select>
						</div>
						<div class="afc-gpon-field is-wide">
							<label for="afc-gpon-vlans"><?php esc_html_e( 'Tagged VLANs', 'airfiber-centralized' ); ?></label>
							<input id="afc-gpon-vlans" name="vlans" type="text" value="510,399" required>
						</div>
						<button type="submit" class="afc-gpon-primary" data-afc-gpon-preview><?php esc_html_e( 'Preview changes', 'airfiber-centralized' ); ?></button>
					</form>

					<div class="afc-gpon-plan" data-afc-gpon-plan>
						<div class="afc-gpon-empty"><strong><?php esc_html_e( 'No changes are sent during preview.', 'airfiber-centralized' ); ?></strong><p><?php esc_html_e( 'Airfiber will first inspect the ONU and show which items will be created or reused.', 'airfiber-centralized' ); ?></p></div>
					</div>
				</div>
			</section>
		</div>
	</section>
</div>
