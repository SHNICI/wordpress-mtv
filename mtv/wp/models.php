<?php
/**
 * @package MTV
 * @version 1.0
 */

namespace mtv\wp\models;

use mtv\models\Model,
    mtv\models\Collection,
    mtv\models\ModelParseException,
    mtv\models\ModelNotFound,
    WPException,
    JsonableException,
    WP_Query;

/**
 * Wordpress models
 **/
class Post extends Model {
    public function __toString() {
        return $this->attributes['post_title'];
    }

    public function save() {
        $data = $this->attributes;

        if ( ! empty($data['blogid']) ) {
            $blogid = $data['blogid'];
            unset( $data['blogid'] );
        } else $blogid = get_current_blog_id();

        if ( ! empty($data['post_meta']) ) {
            $meta = $data['post_meta'];
            unset( $data['post_meta'] );
        }

        if ( isset($data['post_format']) ) {
            $post_format = $data['post_format'];
            unset( $data['post_format'] );
        }

        if ( ! empty( $data['id'] ) ) {
            $data['ID'] = $data['id'];
            unset($data['id']);
        }

        switch_to_blog( $blogid );

        if ( empty( $data['ID'] ) )
            $postid = wp_insert_post( $data, true );
        else
            $postid = wp_insert_post( $data, true );

        if ( is_wp_error( $postid ) )
            throw new Exception($postid->get_error_message());
        else if ( $postid == 0 )
            throw new Exception("Couldn't update the post");

        if ( ! empty( $meta ) ) {
            foreach ( $meta as $key => $val ) 
                update_post_meta( $postid, $key, $val );
        }

        if ( isset($post_format) )
            set_post_format( $postid, $post_format );

        restore_current_blog();

        $this->id = $postid;
        $this->fetch();
    }

    public function fetch() {
        if ( empty($this->attributes['blogid']) || empty($this->attributes['id']) )
            throw new BadMethodCallException("Need a blogid and post id to fetch a post");
        switch_to_blog( $this->attributes['blogid'] );
        $post =& get_post( $this->attributes['id'] );
        if ( $post === NULL ) {
            restore_current_blog();
            throw new ModelNotFound("Post", "Post not found");
        }
        $this->reload( $post );

        restore_current_blog();
    }

    public function parse( $postdata ) {
        // Use the parent parse
        $ret =& parent::parse( $postdata );

        # Take only the fields we need, put them in a temp array
        $ret['blogid']    = get_current_blog_id();

        # Fill up the meta attribute with post meta
        $ret['post_meta'] = array();
        $meta_keys = get_post_custom_keys($ret['id']);
        if ( is_array( $meta_keys ) )
            foreach( $meta_keys as $key )
                $ret['post_meta'][$key] = get_post_meta($ret['id'], $key, true);

        # Add some special fields depending on the post type
        switch($ret['post_type']) {
            case 'attachment':
                $ret['url'] = wp_get_attachment_url($ret['id']);
                $ret['thumb_url'] = wp_get_attachment_thumb_url($ret['id']);
                break;
            case 'post':
                # wp_trim_excerpt needs a global post var
                setup_postdata( $postdata );
                $ret['post_excerpt'] = wp_trim_excerpt($ret['post_excerpt']);
                $content = get_the_content("more", 1);

                $ret['post_format']  = get_post_format( $ret['id'] );
                $ret['permalink']    = get_permalink( $ret['id'] );
                break;
        }

        return $ret;
    }
}

class PostCollection extends Collection {
    public static $model = 'mtv\wp\models\Post';

    public $wp_query;

    public static function filter( $kwargs ) {
        global $post;
        $tmp_post = $post;

        $ret = new PostCollection();

        $ret->query = new WP_Query( $kwargs );

        $ret->query->get_posts();

        foreach( $ret->query->posts as $post ) {
            $p = new Post();
            try {
                $p->reload($post);
                $ret->add($p);
            } catch(ModelParseException $e) {
                # post is bad for some reason, skip it
                continue;
            }
        }

        global $post;
        $post = $tmp_post;

        return $ret;
    }
}

class User extends Model {
    public $defaults = array(
        'id' => '0',
        'user_login' => 'guest',
        'sites' => array(),
    );
    public $json_fields = array( "id", "user_login",
        "user_url", "user_email", "display_name", "nickname", "first_name",
        "last_name", "user_description", "can_reblog", "zip", "capabilities",
        "jabber", "aim", "yim", "primary_blog", "user_level", "avatar");
    public $editable_json_fields = array( "user_url", "user_email", "nickname",
        "first_name", "last_name", "user_description", "zip",
        "user_pass", "jabber", "aim", "yim", "userpic", "userthumb" );

    public function initialize( $attrs ) {
        if ( empty($attrs['avatar']) )
            $this->avatar = get_avatar($attrs['id']);
    }

    public function __toString() {
        return $this->display_name;
    }

    public function save() {

        // split the incoming data into stuff we can pass to wp_update_user and
        // stuff we have to add with update_user_meta
        $userdata = parse_user( $this->attributes );
        $usermeta = array_diff_assoc( $this->attributes, $userdata );
        $removemeta = array_diff(
            array_keys($this->_previous_attributes),
            array_keys($this->attributes)
        );

        unset($usermeta['id']); // make sure we don't accidently save the id as meta

        // Create
        if ( empty($this->id) ) {
            // Validate username and email
            $result = wpmu_validate_user_signup($userdata['user_login'], $userdata['user_email']);
            if ( $result['errors']->get_error_code() )
                throw new WPException($result['errors']);

            // create the new user with all the basic data
            // wp_update_user has bugs that doesn't let you create a user with it
            // http://core.trac.wordpress.org/ticket/17009
            // TODO: just use wp_update_user once the bug is fixed
            $user_id = wp_create_user( $userdata['user_login'], $userdata['user_pass'], $userdata['user_email'] );
            if ( is_wp_error($user_id) )
                throw new WPException($user_id);

            // We should keep track of our user id
            $userdata['ID'] = $user_id;
            $this->id = $user_id;

        // Update
        } else {
            // Don't accidently set our password to empty
            if ( isset($userdata['user_pass']) && trim($userdata['user_pass']) == '' )
                unset( $userdata['user_pass'] );

            // Check which data has changed
            $data_to_update = array_diff_assoc( $userdata, (array) get_userdata( $this->id ) );

            // If we don't have any changes, we don't have to update!
            if ( empty( $data_to_update ) ) $userdata = false;
            else $userdata = array_merge( array('ID' => $this->id), $data_to_update);
        }

        // If we don't have any userdata, we don't have to update!
        if ( $userdata ) {
            $user_id = wp_update_user( $userdata );
            if ( is_wp_error($user_id) )
                throw new WPException($user_id);
        }

        // Update user meta with leftover data
        foreach ( $usermeta as $key => $val )
            update_user_meta( $this->id, $key, $val);

        // Remove any deleted meta
        foreach ( $removemeta as $key )
            delete_user_meta( $this->id, $key );

        $this->fetch();
    }

    public function fetch() {
        $this->reload( get_userdata( $this->id ) );
    }

    public function parse( $userdata ) {
        // Use the parent parse
        $ret = parent::parse( $userdata );

        // get the html to display the users avatar
        $ret['avatar'] = get_avatar( $ret['id'] );

        // get the capabilities into a usable format
        $ret['capabilities'] = array();
        foreach ( $ret as $k => $v ) {
            if ( $k == 'wp_capabilities' ) {
                $ret['capabilities']['1'] = implode(array_keys($ret[$k]));
                unset($ret[$k]);
            } else if ( preg_match('/wp_(\d+)_capabilities/', $k, $matches) ) {
                $ret['capabilities'][$matches[1]] = implode(array_keys($ret[$k]));
                unset($ret[$k]);
            }
        }

        return $ret;
    }

    /**
     * signon
     * Takes an array of keyword arguments, attempts to find the user and sign him/her on.
     **/
    public static function signon( $kwargs ) {
        $creds = array();

        if ( ! empty( $kwargs['user_login'] ) ) {
            $creds['user_login'] = $kwargs['user_login'];
        } elseif ( ! empty( $kwargs['user_email'] ) ) {
            $user = UserCollection::get_by( array( 'user_email' => $kwargs['user_email'] ) );
            $creds['user_login'] = $user->user_login;
        } else throw new JsonableException("Please enter your user name or email address.");

        if ( empty( $kwargs['user_pass'] ) ) throw new JsonableException('Please enter your password.');

        $creds['user_password'] = $kwargs['user_pass'];

        $result = wp_signon($creds, true);

        if ( is_wp_error($result) ) {
            throw new WPException($result);
        } else {
            // TODO:
            // Implement "remember me" functionality with wp_set_auth_cookie
            wp_set_auth_cookie($result->ID, true);
            // wp_set_current_user($result->ID);
        }

        $user = new User;
        $user->reload( $result );
        return $user;
    }

    /**
     * sites
     * Returns a SiteCollection containing all the sites this user is connected to.
     **/
    public function sites() {
        if ( empty($this->_sites) )
            $this->_sites = SiteCollection::for_user(array('user_id'=>$this->id));
        return $this->_sites;
    }

    public function to_json() {
        return parent::to_json() + array('sites'=>$this->sites()->to_json());
    }
}

class UserCollection extends Collection {
    public static $model = 'mtv\wp\models\User';

    public static function get_by( $kwargs ) {
        if ( isset($kwargs['user_email']) ) {
            $userid = get_user_id_from_string($kwargs['user_email']);
            if ( $userid === 0 ) throw new JsonableException("I don't know that email address");
        } else if ( isset($kwargs['user_login']) ) {
            $userid = get_user_id_from_string($kwargs['user_login']);
            if ( $userid === 0 ) throw new JsonableException("I don't know that user name");
        } else throw new NotImplementedException();

        $user = new User( array( 'id'=>$userid ) );
        $user->fetch();
        return $user;
    }

    public static function get_current() {
        $userid = get_current_user_id();
        if ( empty($userid) )
            return new User();
        else {
            $user = new User( array( 'id'=>$userid ) );
            $user->fetch();
            return $user;
        }
    }

    /**
     * Returns an array of users based on criteria
     * Proxies to the WordPress get_users function internally. Use this function as you would use
     * get_users.
     *
     * http://codex.wordpress.org/Function_Reference/get_users
     **/
    public static function filter( $kwargs ) {
        # We set the blog_id query param to zero, so WordPress doesn't filter
        # the query based on the permissions for this blog. Hacky.
        if ( !isset($kwargs['blog_id']) ) $kwargs['blog_id'] = 0;

        $users = get_users( $kwargs );
        $collection = new UserCollection();
        foreach ($users as $u) {
            $new_user = new User;
            $new_user->reload( $u );
            $collection->add( $new_user );
        }
        return $collection;
    }
}

class Site extends Model {

    public function fetch() {
        $this->reload( get_blog_details( $this->id ) );
    }

    public function parse( $data ) {
        // Make sure we have an array and not an object
        if ( is_object($data) ) $data = (array) $data;

        // figure out where the id is
        if ( !empty($data['userblog_id']) ) {
            $data['id'] = $data['userblog_id'];
            unset($data['userblog_id']);
        } else if ( !empty($data['blog_id']) ) {
            $data['id'] = $data['blog_id'];
            unset($data['blog_id']);
        }

        return $data;
    }

}

class SiteCollection extends Collection {
    public static $model = 'mtv\wp\models\Site';

    public static function for_user( $kwargs ) {
        if ( isset($kwargs['user_id']) ) {
            $userid = $kwargs['user_id'];
        } else if ( isset($kwargs['user_email']) ) {
            $userid = get_user_id_from_string($kwargs['user_email']);
            if ( $userid === 0 ) throw new JsonableException("I don't know that email address");
        } else if ( isset($kwargs['user_login']) ) {
            $userid = get_user_id_from_string($kwargs['user_login']);
            if ( $userid === 0 ) throw new JsonableException("I don't know that username");
        } else throw new NotImplementedException();

        $blogdata = get_blogs_of_user($userid);
        $sites = new SiteCollection;
        if ( !empty($blogdata) ) {
            foreach($blogdata as $b) {
                $site = new Site();
                $site->reload($b);
                $sites->add($site);
            }
        }
        return $sites;
    }

    public static function published( ) {
        global $wpdb;

        $query = "select * from wp_blogs where public='1' and archived='0' and spam='0' and deleted='0' and mature='0'";
        return new SiteCollection($wpdb->get_results($query, ARRAY_A));
    }
}
/**
 * Utilities
 **/
function rand_username( $length = 8,
        $chars = 'abcdefghijklmnopqrstuvwxyz1234567890') {
    // Length of character list
    $chars_length = (strlen($chars) - 1);

    // Start our string
    $string = $chars{rand(0, $chars_length)};
    // Generate random string
    for ($i = 1; $i < $length; $i = strlen($string))
    {
        // Grab a random character from our list
        $r = $chars{rand(0, $chars_length)};
        // Make sure the same two characters don't appear next to each other
        if ($r != $string{$i - 1}) $string .=  $r;
    }
    // Return the string
    return $string;
}

# this returns an array of fields that wordpress's update_user can handle
function parse_user( $userdata ) {
    $user_fields = array(
        "ID",
        "user_pass",
        "user_login",
        "user_nicename",
        "user_url",
        "user_email",
        "display_name",
        "nickname",
        "first_name",
        "last_name",
        "description",
        "rich_editing",
        "user_registered",
        "role",
        "jabber",
        "aim",
        "yim"
    );
    foreach( $userdata as $key => $val )
        if ( ! in_array( $key, $user_fields ) )
            unset( $userdata[$key] );
    return $userdata;
}

