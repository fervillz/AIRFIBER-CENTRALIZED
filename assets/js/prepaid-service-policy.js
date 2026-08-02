( function () {
	'use strict';

	function updateCycleSheet() {
		const sheet = document.querySelector( '.afc-cycle-override-sheet' );
		if ( ! sheet ) {
			return;
		}

		const help = sheet.querySelector( '.afc-cycle-sheet-help' );
		if ( help ) {
			help.textContent = 'Prepaid policy: an early payment keeps the customer’s unused paid days, while a late payment starts on the payment date. 15D or 30D adds that exact paid period. Automatic internet grace is not added; Promise to Pay is the manual exception.';
		}

		const choices = sheet.querySelectorAll( '.afc-cycle-choice-grid label' );
		choices.forEach( function ( label ) {
			const input = label.querySelector( 'input[name="afc-cycle-choice"]' );
			const small = label.querySelector( 'small' );
			if ( input && small ) {
				small.textContent = '15' === input.value ? 'Add 15 paid days' : 'Add 30 paid days';
			}
		} );
	}

	function updateLegacyPills() {
		document.querySelectorAll( '.afc-cycle-pill' ).forEach( function ( pill ) {
			if ( 'MTH' === pill.textContent.trim() ) {
				pill.textContent = '30D';
				pill.title = 'Legacy account: the next payment will convert it to rolling 30-day prepaid.';
			}
		} );
	}

	function refresh() {
		updateCycleSheet();
		updateLegacyPills();
	}

	document.addEventListener( 'DOMContentLoaded', refresh );
	new MutationObserver( refresh ).observe( document.documentElement, { childList: true, subtree: true } );
} )();
