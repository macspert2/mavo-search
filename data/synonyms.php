<?php
/**
 * Curated synonyms, per language. Deliberately few.
 *
 * Two shapes:
 *
 *   [ 'leucade', 'lefkada' ]        equivalents: each finds the others
 *   'rando' => [ 'randonnée' ]      one way: "rando" also searches "randonnée",
 *                                   not the reverse
 *
 * Single words only; written with accents, folded on load. A synonym match
 * weighs 0.8 of the word itself, so the visitor's own word still ranks first.
 *
 * Accommodation words are left out on purpose: hôtel, logement, hébergement,
 * gîte, Ferienwohnung are not the same thing to someone booking one.
 *
 * Filter: mavo_search_synonyms. Relevanssi had none configured.
 */

defined( 'ABSPATH' ) || exit;

return [
	'fr' => [
		[ 'leucade', 'lefkada', 'lefkas' ],
		[ 'édimbourg', 'edinburgh' ],
		[ 'londres', 'london' ],
		[ 'madère', 'madeira' ],
		[ 'majorque', 'mallorca' ],
		[ 'minorque', 'menorca' ],
		[ 'pouilles', 'puglia' ],
		[ 'vtt', 'vélo' ],
		'rando'  => [ 'randonnée' ],
		'randos' => [ 'randonnées' ],
		'ado'    => [ 'adolescent' ],
		'ados'   => [ 'adolescents' ],
	],
	'en' => [
		[ 'hike', 'hiking', 'hikes' ],
		[ 'bike', 'biking', 'cycling' ],
		[ 'kids', 'children' ],
		[ 'lefkada', 'lefkas' ],
		[ 'teens', 'teenagers' ],
	],
	'de' => [
		[ 'wanderung', 'wandern', 'wanderungen' ],
		[ 'radtour', 'radfahren', 'fahrrad' ],
		[ 'lefkada', 'lefkas' ],
	],
];
