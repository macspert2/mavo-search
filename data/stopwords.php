<?php
/**
 * Stopwords: never indexed, and never required of a document in a query.
 *
 * Deliberately short. Relevanssi ran a 600-word French list on every language,
 * which dropped "deux", "haut", "loin", "frais", "bon" — words a travel title
 * means. Travel phrases are full of small words ("où dormir", "à vélo", "en
 * famille"): the content words still have to match, and the whole phrase is
 * compared against titles and text separately, stopwords included.
 *
 * Written with accents; folded on load. Filter: mavo_search_stopwords.
 */

defined( 'ABSPATH' ) || exit;

return [
	'fr' => [
		'le', 'la', 'les', 'un', 'une', 'des', 'de', 'du', 'et', 'ou', 'où', 'à', 'au', 'aux', 'en', 'dans', 'par',
		'pour', 'sur', 'avec', 'ce', 'cet', 'cette', 'ces', 'qui', 'que', 'quoi', 'est', 'sont', 'il', 'elle', 'ils',
		'elles', 'on', 'nous', 'vous', 'je', 'tu', 'ne', 'pas', 'se', 'sa', 'son', 'ses', 'leur', 'leurs', 'mon',
		'ma', 'mes', 'notre', 'nos', 'votre', 'vos', 'y', 'qu', 'lui', 'mais', 'donc', 'car', 'ni',
	],
	'en' => [
		'the', 'a', 'an', 'and', 'or', 'of', 'in', 'on', 'at', 'to', 'for', 'with', 'by', 'from', 'is', 'are', 'was',
		'were', 'be', 'it', 'its', 'this', 'that', 'these', 'those', 'as', 'our', 'we', 'you', 'your', 'my', 'me',
		'he', 'she', 'they', 'them', 'their', 'but', 'not', 'so',
	],
	'de' => [
		'der', 'die', 'das', 'den', 'dem', 'des', 'ein', 'eine', 'einen', 'einem', 'einer', 'eines', 'und', 'oder',
		'in', 'im', 'an', 'am', 'auf', 'aus', 'bei', 'mit', 'nach', 'von', 'vom', 'zu', 'zum', 'zur', 'für', 'ist',
		'sind', 'es', 'sie', 'wir', 'ihr', 'ich', 'du', 'er', 'wie', 'wo', 'was', 'nicht', 'auch', 'aber', 'unser',
		'unsere', 'euch', 'uns',
	],
];
