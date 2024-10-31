<?php

/*
Plugin Name: Multilingual Text
Plugin URI: http://wordpress.org/extend/plugins/multilingual-text/
Version: 1.4
Description: Have a text in multiple languages. Absolute simple, no changes required.
Author: ALeX Kazik
Author URI: http://alex.kazik.de/
License: GPL2
*/

/*
    Copyright 2011  ALeX Kazik  (email : alex@kazik.de)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as 
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

/*
** our class
*/

final class Multilingual_Text {
	
	/*
	** variables
	*/
	
	// js variables (to make it switchable)
	private static $texts = array();
	private static $flags = array();
	private static $next_id = 1;
	// settings
	private static $flags_dir = '%WPC%/plugins/multilingual-text/flags';
	private static $flags_show_single;
	private static $flags_where;
	private static $order_title, $order_flags;
	// bot mode
	private static $bot = false;
	private static $bot_only_deflang = false;
	// only deflang
	private static $only_deflang = false;
	// self::Title	
	private static $title_multilingual = false;
	// the order of languages (based on settings)
	private static $langs_user_order, $langs_system_order, $langs_flags_order, $langs_title_order;
	
	/*
	** creation - add all hooks/filters/options
	*/
	
	static function create(){
		add_action('init', array(__CLASS__, 'hook_init'));
		add_action('wp_head', array(__CLASS__, 'hook_wp_head'));
		add_action('wp_title', array(__CLASS__, 'hook_the_title'));
		add_action('the_title', array(__CLASS__, 'hook_the_title'));
		add_action('the_content', array(__CLASS__, 'hook_the_content'));
		add_action('wp_footer', array(__CLASS__, 'hook_wp_footer'));
		add_action('admin_init', array(__CLASS__, 'hook_admin_init'));

		add_filter('plugin_row_meta', array(__CLASS__, 'hook_plugin_row_meta'), 10, 2);
		add_filter('plugin_action_links', array(__CLASS__, 'hook_plugin_action_links'), 10, 2);

		add_filter('get_bloginfo_rss', array(__CLASS__, 'hook_get_bloginfo_rss'), 10, 1);

		add_option('multilingual_text_languages', 'gb,de', '', 'yes');
		add_option('multilingual_text_show_single', false, '', 'yes');
		add_option('multilingual_text_flags_dir', self::$flags_dir, '', 'yes');
	}
	
	
	/*
	** init - get requested languages...
	*/
	
	static function hook_init(){
		// identofy a bot
		self::$bot = isset($_SERVER['HTTP_USER_AGENT']) && strstr(strtolower($_SERVER['HTTP_USER_AGENT']), 'bot') !== false;
		// get the flags dir
		$flags_dir = get_option('multilingual_text_flags_dir');
		if(is_string($flags_dir)){
			self::$flags_dir = $flags_dir;
		}
		// compute the flags dir
		self::$flags_dir = trailingslashit(self::$flags_dir);
		if(substr(self::$flags_dir, 0, 6) == '%WPC%/'){
			self::$flags_dir = trailingslashit(WP_CONTENT_URL).substr(self::$flags_dir, 6);
		}
		// order_title
		self::$order_title = get_option('multilingual_text_title_order') === 'user' ? 'user' : 'system';
		// order_flags
		self::$order_flags = get_option('multilingual_text_flag_order') === 'user' ? 'user' : 'system';
		// show a single flag?
		self::$flags_show_single = get_option('multilingual_text_show_single') === '1';
		// show the flags where?
		self::$flags_where = get_option('multilingual_text_flags_where');
		if(!is_string(self::$flags_where) || false === array_search(self::$flags_where, array('text', 'title', 'other'))){
			self::$flags_where = 'text';
		}
		// get the default languages
		self::$langs_system_order = get_option('multilingual_text_languages');
		if(is_string(self::$langs_system_order)){
			// split default languages
			self::$langs_system_order = explode(',', self::$langs_system_order);
			// the order of the langs
			self::$langs_user_order = array();
			if(self::$bot){
				// if a bot, always use system langs
			}else if(isset($_COOKIE['multilingual_text_config'])){
				// get the requested languages (cookie)
				self::$langs_user_order = array_flip(explode(',', $_COOKIE['multilingual_text_config']));
			}else if(isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])){
				// get the requested languages (browser default)
				$L = explode(',', strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE']));
				foreach($L AS $l){
					$l = split('[-;]', $l);
					self::$langs_user_order[$l[0]] = true;
				}
			}
			// strip out all unknown langs
			foreach(self::$langs_user_order AS $l => $true){
				if(false === array_search($l, self::$langs_system_order, true)){
					unset(self::$langs_user_order[$l]);
				}
			}
			// add all (yet not used) languages
			foreach(self::$langs_system_order AS $l){
				if(!isset(self::$langs_user_order[$l])){
					self::$langs_user_order[$l] = true;
				}
			}
			// get the langs (only the keys = langs)
			self::$langs_user_order = array_keys(self::$langs_user_order);
		}else{
			self::$langs_system_order = array(); // empty array
		}
		if(count(self::$langs_system_order) == 0){
			// problem with langs... default to 'gb' only
			self::$langs_system_order = self::$langs_user_order = array('gb');
		}
		// setup the ordering for the flags and the titles (if only one should be displayed)
		if(self::$order_flags == 'system'){
			self::$langs_flags_order = &self::$langs_system_order;
		}else{
			self::$langs_flags_order = &self::$langs_user_order;
		}
		if(self::$order_title == 'system'){
			self::$langs_title_order = &self::$langs_system_order;
		}else{
			self::$langs_title_order = &self::$langs_user_order;
		}
	}

	/*
	** on feeds, show only default language
	*/
	
	static function hook_get_bloginfo_rss($pass){
		self::$only_deflang = true;
		return $pass;
	}

	/*
	** wp_head - add css
	*/
	
	static function hook_wp_head(){
		if(self::$bot && !is_singular()){
			// BOT: on an overview page (blog/archive/tag/...) only show the def language
			self::$bot_only_deflang = true;
		}
		if(self::$bot){
			// bot mode -> no language switch
		}else{
			echo '<style type="text/css">'."\n";
			echo '.multilingual_text { text-align: right; float: right; padding-left: 10px; padding-bottom:2px }'."\n";
			echo '.multilingual_text img { border: 0px }'."\n";
			echo '</style>';
		}
	}
	
	/*
	** "the_title"
	*/
	
	static function hook_the_title($title){
		$texts = self::parse_languages($title);
		if(self::$only_deflang){
			foreach(self::$langs_system_order AS $lng){
				if(isset($texts[$lng])){
					return $texts[$lng];
				}
			}
		}else if(self::$title_multilingual){
			return self::generate_texts($texts);
		}else{
			foreach(self::$langs_title_order AS $lng){
				if(isset($texts[$lng])){
					return $texts[$lng];
				}
			}
		}
	}
	
	/*
	** "the_content"
	*/
	
	static function hook_the_content($content){
		$ret = '';
		$texts = self::parse_languages($content);
		if(self::$only_deflang){
			foreach(self::$langs_system_order AS $lng){
				if(isset($texts[$lng])){
					return $texts[$lng];
				}
			}
		}
		if(self::$flags_where == 'text'){
			$ret .= self::generate_flags($texts);
		}
		$ret .= self::generate_texts($texts);
		return $ret;
	}
	
	/*
	** footer - the javascript file
	*/
	
	static function hook_wp_footer(){
		if(self::$bot){
			// bot mode -> no language switch at all
			return;
		}
		echo '<script type="text/javascript">'."\n".'//<![CDATA['."\n";
		$expires = gmdate('r', time()+365*86400);
		if(count(self::$texts) == 0 && count(self::$flags) == 0){
			echo 'function multilingual_text_switch(lng){}';
		}else{
			echo 'var multilingual_text_texts = '.json_encode(self::$texts).';';
			echo 'var multilingual_text_flags = '.json_encode(self::$flags).';';
			echo 'var multilingual_text_langs = '.json_encode(self::$langs_user_order).';';

			echo 'function multilingual_text_switchto(arr, lng){';
			echo 	'for(var i=1; i<arr.length; i++){';
			echo 		'if(arr[i] == lng){';
			echo 			'arr.splice(i, 1);'; // remove lng at postition i
			echo 			'arr.unshift(lng);'; // add lng as the first element
			echo 			'return arr;';
			echo 		'}';
			echo 	'}';
			echo 	'return false;'; // lng not found or is already the first element
			echo '}';

			echo 'function multilingual_text_switch(lng){';
			echo 	'if(multilingual_text_langs[0] == lng){';
						// this is already the active language
			echo 		'return false;';
			echo 	'}';
			echo 	'for(var id in multilingual_text_texts){';
			echo 		'var arr = multilingual_text_switchto(multilingual_text_texts[id], lng);';
			echo 		'if(arr != false){';
			echo 			'document.getElementById(id+arr[1]).style.display = \'none\';';
			echo 			'document.getElementById(id+arr[0]).style.display = \'block\';';
			echo 			'multilingual_text_texts[id] = arr;';
			echo 		'}';
			echo 	'}';
			echo 	'for(var id in multilingual_text_flags){';
			echo 		'var arr = multilingual_text_switchto(multilingual_text_flags[id], lng);';
			echo 		'if(arr != false){';
			echo 			'document.getElementById(id+arr[1]).style.opacity = 0.4;';
			echo 			'document.getElementById(id+arr[0]).style.opacity = 1.0;';
			echo 			'multilingual_text_flags[id] = arr;';
			echo 		'}';
			echo 	'}';
			echo 	'var lngs = multilingual_text_switchto(multilingual_text_langs, lng);';
			echo 	'if(lngs != false){';
			echo 		'multilingual_text_langs = lngs;';
			echo 		'document.cookie = \'multilingual_text_config=\'+encodeURIComponent(multilingual_text_langs.join(\',\'))+\';path=/;expires='.$expires.'\';';
			echo 	'}';
			echo 	'return false;'; // false make the link not be executed
			echo '}';
		}
	
		if(isset($_COOKIE['multilingual_text_config'])){
			// refresh the cookie
			echo 'document.cookie = \'multilingual_text_config='.urlencode(implode(',', self::$langs_user_order)).';path=/;expires='.$expires.'\';';
		}
		
		echo "\n".'//]]>'."\n".'</script>'."\n";
	}
	
	/*
	** Admin Interface
	*/
	
	static function hook_admin_init() {
		// Add the section to general settings so we can add our fields to it
		add_settings_section('multilingual_text_setting_section',
			'<a name="multilingual_text">Multilingual Text</a>',
			create_function(
				'',
				'echo \'<p>In a text use "[:gb]" to specify that the following text is in the "gb" language, or "[:de,gb]" for de+gb language, or "[:*]" for all languages. Defaults to the default language (the first in the field below).</p>\';'
			),
			'general'
		);
		
		// Add the field with the names and function to use for our new
		// settings, put it in our new section
		add_settings_field('multilingual_text_languages',
			'Languages',
			create_function(
				'',
				'echo \'<input name="multilingual_text_languages" type="text" value="\'.htmlspecialchars(implode(\',\', Multilingual_Text::prefs(\'langs_system_order\'))).\'" class="code" /> (Comma separated list of languages, default(s) first)\';'
			),
			'general',
			'multilingual_text_setting_section'
		);
		register_setting('general','multilingual_text_languages');
		
		// Add the field with the names and function to use for our new
		// settings, put it in our new section
		add_settings_field('multilingual_text_title_order',
			'Which Title should be displayed',
			create_function(
				'',
				'echo \'<select name="multilingual_text_title_order">\';
				$sel = Multilingual_Text::prefs(\'order_title\');
				foreach(array(\'system\' => \'System default\', \'user\' => \'User default\') AS $id => $desc){
					echo \'<option value="\'.$id.\'"\'.($id == $sel ? \' selected="selected"\' : \'\').\'>\'.$desc.\'</option>\';
				}
				echo \'</select> (Only affects if the title is entered also multilingual_text)\';'
			),
			'general',
			'multilingual_text_setting_section'
		);
		register_setting('general','multilingual_text_title_order');
		
		// Add the field with the names and function to use for our new
		// settings, put it in our new section
		add_settings_field('multilingual_text_flag_order',
			'Order of Flags',
			create_function(
				'',
				'echo \'<select name="multilingual_text_flag_order">\';
				$sel = Multilingual_Text::prefs(\'order_flags\');
				foreach(array(\'system\' => \'In the order as given above\', \'user\' => \'Like the user wanted it\') AS $id => $desc){
					echo \'<option value="\'.$id.\'"\'.($id == $sel ? \' selected="selected"\' : \'\').\'>\'.$desc.\'</option>\';
				}
				echo \'</select>\';'
			),
			'general',
			'multilingual_text_setting_section'
		);
		register_setting('general','multilingual_text_flag_order');
		
		// Add the field with the names and function to use for our new
		// settings, put it in our new section
		add_settings_field('multilingual_text_show_single',
			'Show always a Flag',
			create_function(
				'',
				'echo \'<input name="multilingual_text_show_single" type="checkbox" value="1" class="code" \'.checked(1, Multilingual_Text::prefs(\'flags_show_single\'), false ).\'/> If there is only one language, also show flag\';'
			),
			'general',
			'multilingual_text_setting_section'
		);
		register_setting('general','multilingual_text_show_single');
		
		// Add the field with the names and function to use for our new
		// settings, put it in our new section
		add_settings_field('multilingual_text_flags_dir',
			'Directory of the Flags',
			create_function(
				'',
				'echo \'<input name="multilingual_text_flags_dir" type="text" value="\'.htmlspecialchars(get_option(\'multilingual_text_flags_dir\')).\'" class="code" /> (Defaults to the bultin flags, if starts with \\\'%WPC%/\\\' then the path is relative to the wp-content directory)\';'
			),
			'general',
			'multilingual_text_setting_section'
		);
		register_setting('general','multilingual_text_flags_dir');
		
		// Add the field with the names and function to use for our new
		// settings, put it in our new section
		add_settings_field('multilingual_text_flags_where',
			'Where should the flags be displayed',
			create_function(
				'',
				'echo \'<select name="multilingual_text_flags_where">\';
				$sel = Multilingual_Text::prefs(\'flags_where\');
				foreach(array(\'text\' => \'Next to the text\', \'title\' => \'Next to the title *\', \'other\' => \'Anywhere else **\') AS $id => $desc){
					echo \'<option value="\'.$id.\'"\'.($id == $sel ? \' selected="selected"\' : \'\').\'>\'.$desc.\'</option>\';
				}
				echo \'</select> (* = requires a template change, see doc) (** = for widget use or customized template, see doc)\';'
			),
			'general',
			'multilingual_text_setting_section'
		);
		register_setting('general','multilingual_text_flags_where');
	}
	
	/*
	** Add more links to the plugin
	*/
	
	static function hook_plugin_row_meta($links, $file){
		if($file == plugin_basename(__FILE__)){
			$links[] = '<a href="http://wordpress.org/extend/plugins/multilingual-text/faq/">' . __('FAQ') . '</a>';
			$links[] = '<a href="http://alex.kazik.de/199/multilingual-text/">' . __('Support') . '</a>';
			$links[] = '<a href="https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=MNZS8NX6QR8PG">' . __('Donate') . '</a>';
		}
		return $links;
	}
	
	/*
	** Add Setting link
	*/
	
	static function hook_plugin_action_links($links, $file){
		if($file == plugin_basename(__FILE__)){
			$links[] = '<a href="options-general.php#multilingual_text">' . __('Settings') . '</a>';
		}
		return $links;
	}
	
	/*
	** Custom functions
	*/
	
	static function Title(){
		self::$title_multilingual = true;
		the_title();
		self::$title_multilingual = false;
	}
	
	static function Flags(){
		if(self::$flags_where == 'title'){
			echo self::generate_flags(self::parse_languages($GLOBALS['wp_query']->post->post_content));
		}
	}
	
	static function Parse($text, $with_flags = true, $with_text = true){
		$ret = '';
		$texts = self::parse_languages($text);
		if(self::$only_deflang){
			foreach(self::$langs_system_order AS $lng){
				if(isset($texts[$lng])){
					return $texts[$lng];
				}
			}
		}
		if($with_flags){
			$ret .= self::generate_flags($texts);
		}
		if($with_text){
			$ret .= self::generate_texts($texts);
		}
		return $ret;
	}
	
	static function prefs($name){
		switch($name){
		case 'flags_dir':
			return self::$flags_dir;
		case 'flags_show_single':
			return self::$flags_show_single;
		case 'flags_where':
			return self::$flags_where;
		case 'langs_system_order':
			return self::$langs_system_order;
		case 'langs_user_order':
			return self::$langs_user_order;
		case 'order_title':
			return self::$order_title;
		case 'order_flags':
			return self::$order_flags;
		default:
			return NULL;
		}
	}
	
	static function get_only_deflang(){
		return self::$only_deflang;
	}

	static function set_only_deflang($value){
		self::$only_deflang = $value;
	}
	
	/*
	** Internal functions
	*/
	
	private static function parse_languages($content){
		// if a <p> is wrapped around (like for texts) remove it
		$surrounding_p = preg_match('!^<p>(.*)</p>$!s', $content, $r);
		if($surrounding_p){
			$content = $r[1];
		}
		
		$TEXTS = array();
		$last = array(self::$langs_system_order[0]);
		foreach(preg_split('!(\[:[a-z,*]+\])!', $content, -1, PREG_SPLIT_DELIM_CAPTURE) AS $ln){
			if(trim($ln) == ''){
				// skip empty paragraphs
				continue;
			}
			if(preg_match('!^\[:([a-z]{2}(,[a-z]{2})*)\](.*)$!', $ln, $r)){
				$last = explode(',', $r[1]);
				$ln = $r[3];
			}else if(substr($ln, 0, 4) == '[:*]'){
				$last = array_keys($TEXTS);
				if(strlen($ln) == 4){
					continue;
				}
				$ln = substr($ln, 4);
			}
			foreach($last AS $l){
				$TEXTS[$l] .= $ln;
			}
		}
		
		foreach($TEXTS AS $lng => $txt){
			if(false === array_search($lng, self::$langs_flags_order, true)){
				// we found a new language, append it at the end
				self::$langs_system_order[] = $lng;
				self::$langs_user_order[] = $lng;
			}
			// rewrap a <p> if removed
			if($surrounding_p){
				$TEXTS[$lng] = '<p>'.$txt.'</p>';
			}
		}
		
		return $TEXTS;
	}
	
	private static function generate_flags($TEXTS){
		if(self::$bot){
			// a bot never "sees" a flag
			return '';
		}else if(count($TEXTS) == 1 && !self::$flags_show_single){
			// found only one (and should not always display flags)
			return '';
		}else if(count($TEXTS) == 1){
			// found only one, display the flag; the flag is not switchable
			foreach($TEXTS AS $lng => $txt); // get the key & the value
			return '<span class="multilingual_text">'.
						'<a href="javascript:void" onclick="return multilingual_text_switch(\''.$lng.'\')">'.
							'<img src="'.self::$flags_dir.$lng.'.png" alt="'.$lng.'">'.
						'</a>'.
					'</span>';
		}else{
			$flag_base_id = 'multilingual_text-'.(self::$next_id++).'-';
			self::$flags[$flag_base_id] = array();
			foreach(self::$langs_user_order AS $lng){
				if(isset($TEXTS[$lng])){
					self::$flags[$flag_base_id][] = $lng;
				}
			}

			$flags = array();
				
			foreach(self::$langs_user_order AS $deflng){
				if(isset($TEXTS[$deflng])){
					break;
					// we found the first language, done
				}
			}
			
			foreach(self::$langs_flags_order AS $lng){
				if(isset($TEXTS[$lng])){
					$flags[] = '<a href="javascript:void" onclick="return multilingual_text_switch(\''.$lng.'\')"><img id="'.$flag_base_id.$lng.'" src="'.self::$flags_dir.$lng.'.png"'.($deflng == $lng ? '' : ' style="opacity: 0.4"').' alt="'.$lng.'"></a>';
				}
			}
			
			return '<span class="multilingual_text">'.implode(' ', $flags).'</span>';
		}
	}

	private static function generate_texts($TEXTS){
		if(count($TEXTS) == 1){
			foreach($TEXTS AS $lng => $txt); // get the key & the value
			return $txt;
		}else{
			$ret = '';
			
			foreach(self::$langs_user_order AS $deflng){
				if(isset($TEXTS[$deflng])){
					break;
					// we found the first language, done
				}
			}
			
			if(self::$bot_only_deflang){
				return $TEXTS[$deflng];
			}
			
			if(!self::$bot){
				$text_base_id = 'multilingual_text-'.(self::$next_id++).'-';
				self::$texts[$text_base_id] = array();
				foreach(self::$langs_user_order AS $lng){
					if(isset($TEXTS[$lng])){
						self::$texts[$text_base_id][] = $lng;
					}
				}
			}
			
			foreach($TEXTS AS $lng => $txt){
				if(self::$bot){
					$ret .= '<h4>'.$lng.'</h4>'.$txt;
				}else{
					$ret .= '<span id="'.$text_base_id.$lng.'"'.($deflng == $lng ? '' : ' style="display: none"').'>'.$txt.'</span>';
				}
			}
			
			return $ret;
		}
	}
}

/*
** instanciate the plugin
*/

Multilingual_Text::create();

/*
** the widget
*/

class Multilingual_Text_Widget extends WP_Widget {
	function __construct() {
		parent::__construct('Multilingual_Text_Widget', 'Language', array('classname' => 'Multilingual_Text_Widget', 'description' => 'A language selector'));
	}

	function widget($args, $instance) {
		echo $args['before_widget'];

		if(!empty($instance['_title'])){
			echo $args['before_title'].apply_filters('widget_title', $instance['_title']).$args['after_title'];
		}
		
		$flags_dir = Multilingual_Text::prefs('flags_dir');
		
		echo '<ul>';
		foreach(Multilingual_Text::prefs('langs_system_order') AS $lng){
			echo '<li><a href="#" onclick="return multilingual_text_switch(\''.$lng.'\')">'.
						'<img src="'.$flags_dir.$lng.'.png" alt="'.$lng.'"> '.$instance[$lng].
					'</a></li>';
		}
		echo '</ul>';

		echo $args['after_widget'];
	}

	function update($new_instance, $old_instance) {
		$instance = &$old_instance;
		$instance['_title'] = strip_tags($new_instance['_title']);
		foreach(Multilingual_Text::prefs('langs_system_order') AS $lng){
			$instance[$lng] = strip_tags($new_instance[$lng]);
		}
		return $instance;
	}

	function form($instance) {
		// get the langs
		$langs = Multilingual_Text::prefs('langs_system_order');
		
		// generate the defaults
		$defaults = array('_title' => 'Language');
		foreach($langs AS $lng){
			$defaults[$lng] = $lng.' Name';
		}
	
		// use db or defaults
		$instance = wp_parse_args((array)$instance, $defaults);

		// display form
		echo '<p><label for="'.$this->get_field_id('_title').'">Title: <input class="widefat" id="'.$this->get_field_id('_title').'" name="'.$this->get_field_name('_title').'" type="text" value="'.attribute_escape(strip_tags($instance['_title'])).'" /></label></p>';
		foreach($langs AS $lng){
			echo '<p><label for="'.$this->get_field_id($lng).'">'.$lng.': <input class="widefat" id="'.$this->get_field_id($lng).'" name="'.$this->get_field_name($lng).'" type="text" value="'.attribute_escape(strip_tags($instance[$lng])).'" /></label></p>';
		}
	}
}

add_action('widgets_init', create_function(
	'',
	'return register_widget("Multilingual_Text_Widget");'
));

?>
