<?php
/**
 * BP Activity Replies is a "rustine" plugin that will stop running as soon
 * as the #6057 BuddyPress ticket will be fixed
 *
 * @package   BP Activity Replies
 * @author    imath
 * @license   GPL-2.0+
 * @link      http://imathi.eu
 *
 * @buddypress-plugin
 * Plugin Name:       BP Activity Replies
 * Plugin URI:        http://imathi.eu/tag/rustine
 * Description:       Brings screen notifications when a BuddyPress activity is commented
 * Version:           1.0.0-alpha
 * Author:            imath
 * Author URI:        http://imathi.eu/
 * Text Domain:       bp-activity-replies
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Domain Path:       /languages/
 * GitHub Plugin URI: https://github.com/imath/bp-activity-replies
 */

// Exit if accessed directly
defined( 'ABSPATH' ) or die;

if ( ! class_exists( 'BP_Activity_Replies' ) ) :
/**
 * BP Activity Replies Class
 */
class BP_Activity_Replies {
	/**
	 * If you start me up..
	 */
	public static function start() {
		if ( ! bp_is_active( 'activity' ) ) {
			return;
		}

		$bp = buddypress();

		if ( empty( $bp->activity->replies ) ) {
			$bp->activity->replies = new self;
		}

		return $bp->activity->replies;
	}

	/**
	 * Constructor method.
	 */
	public function __construct() {
		$this->setup_globals();
		$this->setup_hooks();
	}

	/**
	 * Set some globals
	 */
	private function setup_globals() {

		/** Plugin specific globals ***************************************************/

		$this->version  = '1.0.0-alpha';
		$this->domain   = 'bp-activity-replies';
		$this->basename = plugin_basename( __FILE__ );

		$this->slug             = 'replies';
		$this->component_id     = 'activity_replies';
		$this->where_conditions = array();
		$this->new_replies      = array();

		/** BuddyPress specific globals ***********************************************/

		// Required version
		$this->bp_version = '2.1';

		// Version which fixed the ticket
		$this->bp_fixed   = '';
	}

	/**
	 * Checks BuddyPress version
	 */
	public function version_check() {
		// taking no risk
		if ( ! defined( 'BP_VERSION' ) ) {
			return false;
		}

		$return = version_compare( BP_VERSION, $this->bp_version, '>=' );

		if ( ! empty( $this->bp_fixed ) && version_compare( BP_VERSION, $this->bp_fixed, '>=' ) ) {
			$return = false;
		}

		return $return;
	}

	/**
	 * Set Actions & filters
	 */
	private function setup_hooks() {
		/**
		 * Don't do anything if BuddyPress required version is not there
		 * Or if the #6057 BuddyPress ticket has been fixed
		 */
		if ( ! $this->version_check() ) {
			return;
		}

		// load the language..
		add_action( 'bp_init', array( $this, 'load_textdomain' ), 6 );

		// BuddyPress User Nav
		add_action( 'bp_activity_setup_nav', array( $this, 'setup_subnav' ), 20 );

		// BuddyPress Logged in user admin bar
		add_filter( 'bp_activity_admin_nav', array( $this, 'setup_adminbar' ), 20, 1 );

		// Filters to get latest replies about the displayed user updates
		add_filter( 'bp_before_has_activities_parse_args', array( $this, 'adjust_activity_args' ),    10, 1 );
		add_filter( 'bp_activity_get_where_conditions',    array( $this, 'catch_where_conditions' ),  10, 2 );
		add_filter( 'bp_activity_paged_activities_sql',    array( $this, 'adjust_select_activities'), 10, 2 );
		add_filter( 'bp_activity_total_activities_sql',    array( $this, 'adjust_count_activities'),  10, 1 );


		// Activity comment notifications
		add_filter( 'bp_notifications_get_registered_components',   array( $this, 'notifications_component' ),               10, 1 );
		add_action( 'bp_activity_comment_posted',                   array( $this, 'screen_notifications' ),                  10, 2 );
		add_filter( 'bp_notifications_get_notifications_for_user',  array( $this, 'format_notifications'),                   10, 5 );
		add_action( 'bp_activity_replies_member_screen',            array( $this, 'do_activity_replies_actions' ),           10    );
		add_action( 'bp_activity_screen_single_activity_permalink', array( $this, 'do_single_activity_replies_actions' ),    10, 1 );
		add_action( 'bp_activity_deleted_activities',               array( $this, 'delete_activity_replies_notifications' ), 10, 1 );

		// Activity Template
		add_filter( 'bp_get_activity_show_filters_options', array( $this, 'actions_for_replies_context' ), 10, 1 );
		add_filter( 'bp_get_activity_css_class',            array( $this, 'append_reply_class' ),          10, 1 );
	}

	/**
	 * Loads the translation files if any
	 */
	public function load_textdomain() {
		// Traditional WordPress plugin locale filter
		$locale = apply_filters( 'plugin_locale', get_locale(), $this->domain );
		$mofile = sprintf( '%1$s-%2$s.mo', $this->domain, $locale );
		load_plugin_textdomain( $this->domain, false, dirname( $this->basename ) . '/languages' );
	}

	/**
	 * Only apply when on the member's activity replies subnav
	 */
	public function bail() {
		$return = false;

		if ( ! bp_is_user() || ! bp_is_current_component( 'activity' ) || ! bp_is_current_action( $this->slug ) ) {
			$return = true;
		}

		return $return;
	}

	/**
	 * Set BuddyPress user's subnav
	 */
	public function setup_subnav() {
		// Stop if there is no user displayed or logged in
		if ( ! is_user_logged_in() && ! bp_displayed_user_id() ) {
			return;
		}

		// Determine user to use
		if ( bp_displayed_user_domain() ) {
			$user_domain = bp_displayed_user_domain();
		} elseif ( bp_loggedin_user_domain() ) {
			$user_domain = bp_loggedin_user_domain();
		} else {
			return;
		}

		// User link
		$activity_link = trailingslashit( $user_domain . bp_get_activity_slug() );

		bp_core_new_subnav_item( array(
			'name'            => _x( 'Replies', 'Profile activity replies sub nav', 'bp-activity-replies' ),
			'slug'            => $this->slug,
			'parent_url'      => $activity_link,
			'parent_slug'     => bp_get_activity_slug(),
			'screen_function' => array( $this, 'screen_function' ),
			'position'        => 25
		) );
	}

	/**
	 * Set BuddyPress user's admin bar subnav
	 */
	public function setup_adminbar( $admin_nav = array() ) {
		if ( ! is_user_logged_in() ) {
			return $admin_nav;
		}

		$component_id  = buddypress()->activity->id;
		$new_admin_nav = array();
		$do_mentions   = bp_activity_do_mentions();
		$activity_link = trailingslashit( bp_loggedin_user_domain() . bp_get_activity_slug() );
		$replies_nav = array(
			'parent' => 'my-account-' . $component_id,
			'id'     => 'my-account-' . $component_id . '-' . $this->slug,
			'title'  => _x( 'Replies', 'Profile activity replies sub admin nav', 'bp-activity-replies' ),
			'href'   => trailingslashit( $activity_link . $this->slug )
		);

		foreach( $admin_nav as $nav ) {
			$new_admin_nav[] = $nav;

			if ( ! empty( $do_mentions ) && 'my-account-' . $component_id . '-mentions' == $nav['id'] ) {
				$new_admin_nav[] = $replies_nav;
			} elseif ( empty( $do_mentions ) && 'my-account-' . $component_id . '-personal' == $nav['id'] ) {
				$new_admin_nav[] = $replies_nav;
			}
		}

		return apply_filters( 'bp_activity_replies_setup_adminbar', $new_admin_nav, $admin_nav );
	}

	/**
	 * Screen callback for the new subnav item
	 */
	public function screen_function() {
		bp_update_is_item_admin( bp_current_user_can( 'bp_moderate' ), 'activity' );

		do_action( 'bp_activity_replies_member_screen' );

		bp_core_load_template( apply_filters( 'bp_activity_replies_member_template', 'members/single/home' ) );
	}

	/**
	 * Specific activity args to fetch the replies
	 */
	public function adjust_activity_args( $args = array() ) {
		if ( $this->bail() ) {
			return $args;
		}

		$reply_args = array_merge( array(
			'display_comments' => false,
			'show_hidden'      => bp_is_my_profile(),
			'user_id'          => 0,
		), $args );

		return $reply_args;
	}

	/**
	 * Catch the 'where' sql part of the regular activity query
	 */
	public function catch_where_conditions( $where_conditions = array(), $args = array() ) {
		if ( $this->bail() ) {
			return $where_conditions;
		}

		$this->where_conditions = $where_conditions;

		return $where_conditions;
	}

	/**
	 * Rebuild the select query to get latest replies about the displayed user's updates
	 */
	public function adjust_select_activities( $select_activities = '', $args = array() ) {
		global $wpdb;

		if ( $this->bail() ) {
			return $select_activities;
		}

		$bp = buddypress();
		$where = 'WHERE ' . join( ' AND ', $this->where_conditions );
		$where_a = $this->where_conditions;

		if ( ! empty( $where_a['filter_sql'] ) ) {
			unset( $where_a['filter_sql'] );
		}

		if ( ! empty( $where_a['excluded_types'] ) ) {
			unset( $where_a['excluded_types'] );
		}

		$sql_parts = explode( $where, $select_activities );
		$this->sql_replies = array();
		$sql = array();

		$sql_parts = array_map( 'trim', $sql_parts );

		foreach ( $sql_parts as $part ) {
			preg_match('/\b\w+\b/i', $part, $index );

			if ( ! empty( $index[0] ) ) {
				$sql[ strtolower( $index[0] ) ] = $part;
			}
		}

		if ( ! empty( $sql['select'] ) ) {
			$this->sql_replies['select'] = $sql['select'] . ", {$bp->activity->table_name} c";

			$this->sql_replies['where'] = array(
				'a_type' => BP_Activity_Activity::get_in_operator_sql( 'a.type', 'activity_comment' ),
				'a_user' => $wpdb->prepare( 'a.user_id != %d', bp_displayed_user_id() ),
				'c_join' => '( c.id = a.item_id OR c.id = a.secondary_item_id )',
				'c_user' => $wpdb->prepare( 'c.user_id = %d', bp_displayed_user_id() ),
			);

			if ( ! empty( $where_a ) ) {
				$this->sql_replies['where'] = array_merge( $this->sql_replies['where'], $where_a );
			}

			if ( ! empty( $args['filter']['action'] ) ) {
				$this->sql_replies['where']['c_type'] = BP_Activity_Activity::get_in_operator_sql( 'c.type', $args['filter']['action'] );
			}

			if ( ! empty( $sql['order'] ) ) {
				$this->sql_replies['order'] = $sql['order'];
			}
		}

		$sql_replies = $this->sql_replies;
		$sql_replies['where'] = 'WHERE ' . join( ' AND ', $sql_replies['where'] );
		$this->count_replies = $sql_replies;

		if ( ! empty( $this->count_replies['order'] ) ) {
			unset( $this->count_replies['order'] );
		}

		return apply_filters( 'bp_activity_replies_adjust_activities_query', join( ' ', $sql_replies ), $this->sql_replies, $select_activities, $args );
	}

	/**
	 * Never used... Unless somebody is playing with the count_total argument
	 */
	public function adjust_count_activities( $count_activities = '' ) {
		if ( $this->bail() || empty( $this->count_replies ) ) {
			return $count_activities;
		}

		$this->count_replies['select'] = str_replace( 'DISTINCT a.id', 'count( DISTINCT a.id )', $this->count_replies['select'] );

		return apply_filters( 'bp_activity_replies_adjust_count_activities', join( ' ', $this->count_replies ), $this->count_replies, $count_activities );
	}

	/**
	 * Record a screen notification once an activity has been commented
	 * or once a comment has been replied to.
	 */
	public function screen_notifications( $comment_id = 0, $args = array() ) {
		if ( empty( $comment_id ) || empty( $args['activity_id'] ) ) {
			return;
		}

		$bp = buddypress();

		// Set reply object
		$reply = (object) $args;
		$reply->id = $comment_id;

		$activity = new BP_Activity_Activity( $reply->activity_id );

		// Do not record a notification if no activity found
		if ( empty( $activity->id ) ) {
			return;
		}

		// Always notify the main activity author, if not the one who commented
		if ( $activity->user_id != $reply->user_id && bp_is_active( 'notifications' ) ) {
			bp_notifications_add_notification( array(
				'user_id'           => $activity->user_id,
				'item_id'           => $reply->id,
				'secondary_item_id' => $reply->user_id,
				'component_name'    => $this->component_id,
				'component_action'  => 'new_activity_comment'
			) );
		}

		// Stop here if it's not a reply to another comment
		if ( empty( $reply->parent_id ) || ( $reply->activity_id == $reply->parent_id ) ) {
			return;
		}

		$comment = new BP_Activity_Activity( $reply->parent_id );

		// Do not record a notification if commenter == author
		if ( empty( $comment->id ) || $comment->user_id == $reply->user_id || $activity->user_id == $comment->user_id ) {
			return;
		}

		if ( bp_is_active( 'notifications' ) ) {
			bp_notifications_add_notification( array(
				'user_id'           => $comment->user_id,
				'item_id'           => $reply->id,
				'secondary_item_id' => $reply->user_id,
				'component_name'    => $this->component_id,
				'component_action'  => 'new_activity_comment_reply'
			) );
		}
	}

	/**
	 * We need to fake a component as BuddyPress only provide an
	 * action in bp_activity_format_notifications() which avoids the
	 * display of the links into the WP Admin Bar.
	 *
	 * Ideally the switch case should include a default case containing
	 * a filter to let plugins easily add their custom actions to the
	 * activity component.
	 */
	public function notifications_component( $components = array() ) {
		$components[] = $this->component_id;
		return $components;
	}

	/**
	 * Format the notifications for the notification screen & the WP Admin Bar
	 */
	public function format_notifications( $action = '', $item_id = 0, $secondary_item_id = 0, $total_items = 0, $format = 'string' ) {
		// Targetted actions
		$actions = array(
			'new_activity_comment'       => 1,
			'new_activity_comment_reply' => 1,
		);

		// Is it one of the targetted actions ?
		if ( ! isset( $actions[ $action ] ) ) {
			// Return unchanged ref array
			return array(
				$action,
				$item_id,
				$secondary_item_id,
				$total_items,
				$format
			);
		}

		$is_read_screen = bp_is_user_notifications() && bp_is_current_action( 'read' );

		// Main user's activity link
		$activity_link = trailingslashit( bp_loggedin_user_domain() . bp_get_activity_slug() );

		// Permalink to the activity
		$notification_link  = bp_activity_get_permalink( $item_id );

		// If not on read screen append a query var
		if ( ! $is_read_screen ) {
			$notification_link = add_query_arg( array('reply' => $item_id ), $notification_link );
		}

		$notification_title = __( 'New activity comment', 'bp-activity-replies' );

		if ( (int) $total_items > 1 ) {
			$text = sprintf( __( 'You have %1$d new replies to one of your updates', 'bp-activity-replies' ), (int) $total_items );

			if ( 'new_activity_comment_reply' == $action ) {
				$text = sprintf( __( 'You have %1$d new replies to one of your comments', 'bp-activity-replies' ), (int) $total_items );
			}

			// More than one use the replies subnav
			$notification_link = trailingslashit( $activity_link . $this->slug );

			// If not on read screen append a query var
			if ( ! $is_read_screen ) {
				$notification_link = add_query_arg( array( 'n' => $total_items ), $notification_link );
			}
		} else {
			$user_fullname = bp_core_get_user_displayname( $secondary_item_id );
			$text =  sprintf( __( '%1$s replied to one of your updates', 'bp-activity-replies' ), $user_fullname );

			if ( 'new_activity_comment_reply' == $action ) {
				$text = sprintf( __( '%1$s replied to one of your comments', 'bp-activity-replies' ), $user_fullname );
			}
		}

		if ( 'string' == $format ) {
			return '<a href="' . esc_url( $notification_link ) . '" title="' . esc_attr( $notification_title ) . '">' . esc_html( $text ) . '</a>';
		} else {
			return array(
				'text' => $text,
				'link' => $notification_link,
			);
		}
	}

	/**
	 * Mark notifications as read (if any) on the user's activity replies screen
	 */
	public function do_activity_replies_actions() {
		if ( ! bp_is_my_profile() || ! bp_is_active( 'notifications' ) ) {
			return;
		}

		if ( isset( $_GET['n'] ) ) {
			// Get all notifications, as the function is caching its data, no extra queries :)
			$notifications = bp_notifications_get_all_notifications_for_user( bp_loggedin_user_id() );

			// Catch an array of the latest replies
			$this->new_replies = wp_filter_object_list( $notifications, array( 'component_name' => $this->component_id ), 'and', 'item_id' );

			// Add one css rule if needed
			add_action( 'wp_head', array( $this, 'print_style' ) );

			// Mark all notifications as read
			bp_notifications_mark_notifications_by_type( bp_loggedin_user_id(), $this->component_id, '' );
		}
	}

	/**
	 * Mark notifications as read (if any) on the single activity
	 */
	public function do_single_activity_replies_actions( $activity ) {
		if ( ! bp_is_active( 'notifications' ) || ! is_user_logged_in() || ! isset( $_GET['reply'] ) ) {
			return;
		}

		// Mark as read any reply notifications for the current user related to this activity
		bp_notifications_mark_notifications_by_item_id( bp_loggedin_user_id(), (int) $_GET['reply'], $this->component_id, '' );
	}

	/**
	 * Delete all notifications when the activity is deleted
	 */
	function delete_activity_replies_notifications( $activity_ids_deleted = array() ) {
		if ( ! bp_is_active( 'notifications' ) || empty( $activity_ids_deleted ) ) {
			return;
		}

		foreach ( $activity_ids_deleted as $activity_id ) {
			bp_notifications_delete_all_notifications_by_type( $activity_id, $this->component_id );
		}
	}

	/**
	 * I know i should use wp_enqueue_style() but for one unique css rule..
	 *
	 * This will highlight new replies
	 */
	public function print_style() {
		?>
		<style type="text/css" media="screen">
		/*<![CDATA[*/
		#buddypress ul.activity-list li.new-reply {
			background: #fff9db;
			border-top: 1px solid #ffe8c4;
			border-bottom: 1px solid #ffe8c4;
			padding: 15px 10px;
		}
		/*]]>*/
		</style>
		<?php
	}

	/**
	 * Only keep activity types that can be commented in activity dropdown
	 */
	public function actions_for_replies_context( $filters = array() ) {
		if ( $this->bail() ) {
			return $filters;
		}
		$bp = buddypress();
		$replies_filters = $filters;

		$turn_off = 0;
		if ( ! empty( $bp->site_options['bp-disable-blogforum-comments'] ) ) {
			$turn_off = 1;
		}

		$maybe_turn_off = array_fill_keys( array(
			'new_blog_post',
			'new_blog_comment',
			'new_forum_topic',
			'new_forum_post',
		), $turn_off );

		$maybe_turn_off['activity_comment'] = 1;

		// New in BuddyPress 2.2
		if ( function_exists( 'bp_activity_get_post_types_tracking_args' ) ) {
			$bp->activity->track = bp_activity_get_post_types_tracking_args();

			foreach ( $bp->activity->track as $action => $tracking_args ) {
				if ( empty( $tracking_args->activity_comment ) ) {
					$maybe_turn_off[ $action ] = $turn_off;
				}
			}
		}

		foreach( $replies_filters as $key => $value ) {
			if ( ! empty( $maybe_turn_off[ $key ] ) ) {
				unset( $replies_filters[ $key ] );
			}
		}

		return apply_filters( 'bp_activity_replies_actions_for_replies_context', $replies_filters, $filters );
	}

	/**
	 * Add a css class to the activity entry if it's a new reply
	 */
	public function append_reply_class( $activity_class = '' ) {
		if ( $this->bail() || empty( $this->new_replies ) ) {
			return $activity_class;
		}

		$new_replies = array_flip( $this->new_replies );

		if ( isset( $new_replies[ bp_get_activity_id() ] ) ) {
			$activity_class .= ' new-reply';
		}

		return $activity_class;
	}
}
add_action( 'bp_include', array( 'BP_Activity_Replies', 'start' ) );

endif;
