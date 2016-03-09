<?php

/**
 * Class: RW_Remote_Auth_Client_BPGroup
 *
 * adds a adminpage under the users page with
 *     Headline/Menu: Add Group Member
 *     1. CODE field (base64 :    {group_id:'123',url:'http://....'} )
 *     2. List of successfull added group member
 *     3. Name of the group
 *     4. Button Add/Update
 *
 * requires the plugin rw-blog-group-server installed on the buddypress host
 *
 * send a remote json request to the buddypress host to fetch group data
 *     1. url of the buddypress group
 *     2. user_login of the blog admin
 *
 * send a remote json request to the buddypress host to fetch group data
 *     1. group_id     (group slug may be not persistent)
 *     2. user_login of the blog admin
 *
 * expect a json width a
 *    1. list of the member
 *    2. group details (name,url)
 *    or
 *      response error messages (http error code) can be
 *      - admin is not a member (403)
 *      - group not found or deleted (404)
 *      - invalid request (406)
 *      - no json (405)
 *

 * on success
 *    1. adds member of a external buddypress group to a blog
 *    2. adds the group (slug,name,id,host) to current blog options
 *    3. send a success:true request
 *
 * displays buddypress group on the dasboard for all member
 * displays the add group member site
 *
 * Author: Joachim Happel
 * Date: 08.03.2016
 * Time: 01:09
 * @since 0.2.3
 */
class RW_Remote_Auth_Client_BPGroup
{

    public static function init(){


    }

    /**
     * send the cmd 'get_group' to the remote gorup server
     *
     * @hash base64 returns json with:
     *  $group_id  (from external bp group)
     *  $url       (from remot group server)
     *
     * save the to the blog options ( rw_remote_auth_client_bpgroups[ host_group_id : {group_id:'123',url:'http://....'} ]
     *
     * @return a stdClass with the group_details
     *
     *      group->info = stdClass
     *          info->name
     *          info->url
     *
     *      group->members = array{
     *
     *          stdClass Member:
     *              Member->login_name
     *              Member->profil_url
     *
     *      )
     *
     */
    public static function get_group( $hash ){

        $admin = wp_get_current_user();

        $group = json_decode( base64_decode($hash) ) ;

        $args = array(
            'cmd'=>'get_group',
             'data'=>array(
                 'admin'=>$admin->user_login,
                 'group_id'=>$group->group_id
             )
        );

        return self::remote_get( $group->url , $args );

    }


    /**
     * send the cmd 'add_blog' to the remote gorup server
     *
     * @param $hash
     * @return object|WP_Error
     */
    protected static function send_success( $hash ){

        $group = json_decode( base64_decode($hash) ) ;

        $blog = get_bloginfo_rss(get_current_blog_id());


        $args = array(
            'cmd'=>'add_blog',
            'data'=>array(
                'site_url'=>get_home_url(),
                'feed_url'=>$blog->rss2_url,
                'comments_url'=>$blog->comments_rss2_url,
                'blogname'=>$blog->blogname,
                'success'=>true,
                'group_id'=>$group->group_id,)
        );

        return self::remote_get(  $group->url , $args );

    }


    /**
     * @param $url      server endpoint
     * @param $args     url params
     * @return object|WP_Error
     */
    protected static function  remote_get( $url, $args ){

        $endpoint = '/rwgroupinfo';
        if(!strpos($endpoint, $url)){
            $url .= $endpoint;
        }

        $json =  rawurlencode( json_encode( $args) ) ;
        $response = wp_remote_get($url .'/'. $json , array(
            'sslverify'=>false
        ));
        if(is_wp_error($response)){
            $error = $response->get_error_message();
        }else{
            if(
                isset($response['headers']["content-type"])
                    && strpos($response['headers']["content-type"],'application/json') !==false
            ){
                try {
                    $json = json_decode($response['body']);
                    if (is_a($json, 'stdClass') && isset($json->errors) && $json->errors ) {
                        $sever_error = $json->errors;
                        if(is_a($sever_error,'stdClass')){
                            $error = $sever_error->message;
                            $data = $sever_error->data;
                        }else{
                            $error  = $sever_error;
                        }

                    }else{
                        return $json;
                    }

                } catch ( Exception $ex ) {
                    $error = __('Error: Can not decode response.', RW_Remote_Auth_Client::get_textdomain());
                }
            }else{
                $error = __('Error: No valid server response. Context Type: '. $response['headers']["content-type"], RW_Remote_Auth_Client::get_textdomain());
                echo '<pre>';
                echo 'url: ';
                echo $url .'/'. $json;
                echo '<br>';
                var_dump($response['body']);
                echo '</pre>';
            }
        }

        return new WP_Error('remote_auth_response',$error, $response);
    }

    /**
     * Add a new adminsubpage and submenu below User
     */
    public static function add_adminpage(){
        add_submenu_page (
            'users.php',
            __('Add Group Member', RW_Remote_Auth_Client::get_textdomain()),
            __('Add Group Member', RW_Remote_Auth_Client::get_textdomain()),
            'manage_options', 'rw_remote_auth_client_bpgroups',
            array('RW_Remote_Auth_Client_BPGroup','the_adminpage')
            );
    }

    /**
     * Print the adminpage
     */
    public static function the_adminpage(){
        
		//delete_option('rw_remote_auth_client_bpgroups');
        
		?>
        <h2>
            <?php echo __('Add group member from an external buddypress group', RW_Remote_Auth_Client::get_textdomain()); ?>
        </h2>
        <?php
        self::the_form();
        self::groups_loop();
    }

    /**
     * Print the form on the adminpage to create the remote request
     */
    protected static function the_form(){

        if(isset($_POST['rw_remote_auth_client_bpgroups_code'])){
            check_admin_referer('rw_remote_auth_client_bpgroups_addnew');
            $hash = trim($_POST['rw_remote_auth_client_bpgroups_code']);

            $group = self::get_group( $hash );
            if(!is_wp_error($group)){

                if(isset($group->message)){
                    //an error message from blogserver
                    echo '<b style="color:red">Anfrage abgelehnt. Grund: '. $group->message.'</b>';

                    RW_Remote_Auth_Client::notice('error', $group->message);

                    self::remove_hash($hash);

                }else{
                    self::set_hash($hash);
                }
            }
        }

        $sample_hash = array(
            'group_id' => 3,
            'url' => 'http://lernlog.de/rw_groupinfo/'
        );
        $hash = base64_encode( json_encode( $sample_hash ) );
        $form_action = admin_url('users.php?page=rw_remote_auth_client_bpgroups');
        ?>
        <form method="post" action="<?php echo $form_action; ?>">
            <fieldset class="widefat">
            <?php
            wp_nonce_field('rw_remote_auth_client_bpgroups_addnew');
            ?>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="rw_remote_auth_client_bpgroups_code"><?php _e( 'Group Code', RW_Remote_Auth_Client::$textdomain ); ?></label>
                    </th>
                    <td>
                        <textarea style="max-width:500px; width:100%" name="rw_remote_auth_client_bpgroups_code" id="rw_remote_auth_client_bpgroups_code" aria-describedby="bpgroups_code-description"><?php //echo $hash;?></textarea>
                        <p id="bpgroups_code-descriptio" class="description"><?php _e( 'Copy the code from the "External Blogs" page in your group manage page.', RW_Remote_Auth_Client::$textdomain); ?></p>
                    </td>
                </tr>

                </table>
            </fieldset>
            <br/>
            <input type="submit" class="button-primary" value="<?php _e('Save Changes' )?>" />

        </form>
        <?php
    }


    /**
     * Add an existing user from login server     *
     * @param $user_login
     * @use RW_Remote_Auth_Client_User::add_existing_user_from_auth_server
     */
    protected static function add_member_to_blog($user_login){

        $data = RW_Remote_Auth_Client_User::remote_user_get_data($user_login);

        if (isset($data->error)) {   //object contains an error message

            $error = _('Could not get Userdata from login server', RW_Remote_Auth_Client::get_textdomain() );

        }elseif(isset($data->exists) && $data->exists  ) { //object returns an exists flag

            //create a valid set of values als args for wp_insert_user
            $user_details = array(
                'user_login' => $data->user_login,
                'user_nicename' => $data->user_login,
                'nickname' => $data->user_login,
                'display_name' => $data->user_login,
                'user_pass' => $data->user_password,
                'user_email' => $data->user_email,
                'user_registered' => date('Y-m-d H:i:s')

            );



            //insert client user with the response data from auth server
            if ($user_id = wp_insert_user($user_details)) {

                add_user_to_blog(  get_current_blog_id(), $user_id , 'author');



            }else{

                $error = sprintf(
                    __('User "%s" was not added to your site', RW_Remote_Auth_Client::get_textdomain())
                    ,$user_login
                );

            }
        }


    }

    /**
     * Displays a loop of all member
     */
    public static function groups_loop(){
        $groups = self::get_option_groups();
        if($groups){
            foreach($groups as $key=>$group ){

                self::the_group((object)$group);
                self::the_members((object)$group);

            }
        }

    }

    /**
     * Print a single goup with it's member
     * @param $group_id
     */
    protected static function the_group( $group ){
        ?>
        <h3>
            <?php if(isset( $group->url )):?>
                <a href="<?php echo $group->url;?>">
                    <?php echo $group->name;?>
                </a>
            <?php else: ?>
                <?php echo $group->name;?>
            <?php endif; ?>
        </h3>
        <?php
   }

    /**
     * Print a list of group members
     * @param $group_id
     *
     */
    protected static function the_members( $group ){
        if(isset($group->member)){
            $members =  $group->member;
            foreach($members as $member){
                $user = get_user_by('user_login');
                if(!$user){
                    $user = (object) array(
                        'name'=>$member->login_name . '  (not a blog member)'
                    );
                    self::add_member_to_blog($member->login_name);
                }
                ?>
                <li>
                    <a href="<?php echo $member->profil_url ;?>"><?php echo $user->display_name ;?></a>
                </li>
                <?php
            }
        }

    }

    /**
     * fetch group infos from remoteserver
     *
     * @return array
     */
    protected static function get_option_groups(){

        $hashes =  get_option('rw_remote_auth_client_bpgroups') ;

        if(!$hashes) return false;

        $groups = array();

        foreach( $hashes as $hash=>$active ){
            $group = self::get_group( $hash );
            if(is_wp_error($group)){

                $group = (object) array(
                    'name'=>$group->get_error_message()
                );
            }else{
                $groups[] = $group;
            }


        }
        return $groups;

    }

    /**
     * set option
     *
     * @param $hash
     */
    protected static function set_hash($hash){


        $option = get_option('rw_remote_auth_client_bpgroups');

        $option[ $hash ] = 1;

        update_option('rw_remote_auth_client_bpgroups',$option);

        self::send_success($hash);

    }

    protected static function remove_hash($hash){
        $option = get_option('rw_remote_auth_client_bpgroups');
        unset($option[ $hash ]);
        update_option('rw_remote_auth_client_bpgroups',$option);
    }

}