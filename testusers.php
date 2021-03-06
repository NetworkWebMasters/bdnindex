<?php
date_default_timezone_set( 'America/New_York' );
header('Content-Type:text/html; charset=UTF-8');

//Desks, and authors in them
// WordPress user ID => user-readable name
// When people leave, keep them in this array, just add them to the flybys array
$authornames = array(
	'Metro' => array(
		128 => 'Dawn Gagnon',
		63 => 'Judy Harrison',
	),
	'North' => array(
		77 => 'Abigail Curtis',
	),
	'South' => array(
		99 => 'Christopher Cousins',
	),
);

$flybys = array(
		// 'Judy Long' => true,
		// 'Pete Warner' => true,
		// 'Tony Reaves' => true,
		// 'Pattie Reaves' => true,
		// 'Lisa Haberzettl' => true,
		// 'Travis Gass' => true,
		// 'Sean McKenna' => true,
		// 'Andrew Catalina' => true,
        'Joe Mclaughlin' => true,
		'Renee Ordway' => true,
		'Sarah Smiley' => true,
		'Ardeana Hamlin' => true,
		'Reeser Manley' => true,
		'Emmet Meara' => true,
		'Sandy Oliver' => true,
		'Janine Pineo' => true,
		'Wayne Reilly' => true,
		'Dana Wilde' => true,
		'Will Davis' => true,
		'Mal Leary' => true,
		'Paul Warner' => true,
		'Sharon Kiley Mack' => true,
		'Eric Russell' => true,
		'Meg Haskell' => true,
		'Sharon Kiley Mack BDN' => true,
		'Diana Bowley' => true,
		'Dylan Martin' => true,
		'Whit Richardson' => true,
		'Kevin Miller' => true,
		'Dan Barr' => true,
		'Noah Hurowitz' => true,
		'Amber Cronin' => true,
		'Alex Lear' => true,
		'David Harry' => true,
		'Marena Blanchard' => true,
		'Michael Hoffer' => true,
		'Mo Mehlsak' => true,
		'Will Graff' => true,
		'William Hall' => true,
		'Ann Bryant' => true,
		'Bonnie Washuk' => true,
		'Chris Williams' => true,
		'Daniel Hartill' => true,
		'Donna Perry' => true,
		'Erin Cox' => true,
		'Kevin Mills' => true,
		'Kathryn Skelton' => true,
		'Leslie Dixon' => true,
		'Lindsay Tice' => true,
		'Mark LaFlamme' => true,
		'Randy Whitehouse' => true,
		'Scott Taylor' => true,
		'Tony Blasi' => true,
		'Terry Karkos' => true,
		'Tony Reaves SJ' => true,
		'Sun Journal' => true,
		'Andie Hannon' => true,
		'Scott Thistle' => true,
		'Darcie Moore' => true,
		'Times Record' => true,
		'The Forecaster' => true,
		'Andrew Cullen' => true,
		'Matt Wickenheiser' => true,
		'Tom Groening' => true,
		'Andy Neff' => true,
		'Matt Stone' => true,
		'Dave Barber' => true,
		'JT Leonard' => true,
		'Larry Grard' => true,
		'The Associated Press' => true,
		'Reuters' => true,
		'Tom Walsh' => true,
		'Heather Steeves' => true,
		'Kalle Oakes' => true,
        'Robert Long' => true,
        'Matthew Stone' => true,
        'Alex Barber' => true,
		'Brian Swartz' => true,
        'David Fitzpatrick' => true,
);