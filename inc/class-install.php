<?php
/**
 * Class for upgrade / deactivation / uninstall
 */
class BackWPup_Install {

	/**
	 * Creates DB und updates settings
	 */
	public static function activate() {

		//do upgrade from version 2.x
		if ( ! get_site_option( 'backwpup_version' ) && get_option( 'backwpup' ) && get_option( 'backwpup_jobs' ) )
			self::upgrade_from_version_two();

		//create new option on not ms blogs
		if ( ! is_multisite() && ! get_option( 'backwpup_jobs', FALSE ) )
			add_option( 'backwpup_jobs', array(), NULL, 'no' );

		//remove old schedule
		wp_clear_scheduled_hook( 'backwpup_cron' );
		//make new schedule
		$activejobs = BackWPup_Option::get_job_ids( 'activetype', 'wpcron' );
		if ( ! empty( $activejobs ) ) {
			foreach ( $activejobs as $id ) {
				$cron_next = BackWPup_Cron::cron_next( BackWPup_Option::get( $id, 'cron') );
				wp_schedule_single_event( $cron_next, 'backwpup_cron', array( 'id' => $id ) );
			}
		}

		//add check Cleanup schedule
		wp_clear_scheduled_hook( 'backwpup_check_cleanup' );
		wp_schedule_event( time(), 'twicedaily', 'backwpup_check_cleanup' );

		//remove old roles pre v.3.0.9
		$role = get_role( 'administrator' );
		$role->remove_cap( 'backwpup' );
		$role->remove_cap( 'backwpup_jobs' );
		$role->remove_cap( 'backwpup_jobs_edit' );
		$role->remove_cap( 'backwpup_jobs_start' );
		$role->remove_cap( 'backwpup_backups' );
		$role->remove_cap( 'backwpup_backups_download' );
		$role->remove_cap( 'backwpup_backups_delete' );
		$role->remove_cap( 'backwpup_logs' );
		$role->remove_cap( 'backwpup_logs_delete' );
		$role->remove_cap( 'backwpup_settings' );

		//add/overwrite roles
		add_role( 'backwpup_admin', __( 'BackWPup Admin', 'backwpup' ), array(
												  'backwpup' => TRUE, 					// BackWPup general accesses (like Dashboard)
												  'backwpup_jobs' => TRUE,				// accesses for job page
												  'backwpup_jobs_edit' => TRUE,			// user can edit/delete/copy/export jobs
												  'backwpup_jobs_start' => TRUE,		// user can start jobs
												  'backwpup_backups' => TRUE,			// accesses for backups page
												  'backwpup_backups_download' => TRUE,	// user can download backup files
												  'backwpup_backups_delete' => TRUE,	// user can delete backup files
												  'backwpup_logs' => TRUE,				// accesses for logs page
												  'backwpup_logs_delete' => TRUE,		// user can delete log files
												  'backwpup_settings' => TRUE,			// accesses for settings page
											 ) );

		add_role( 'backwpup_check', __( 'BackWPup jobs checker', 'backwpup' ), array(
																'backwpup' => TRUE,
																'backwpup_jobs' => TRUE,
																'backwpup_jobs_edit' => FALSE,
																'backwpup_jobs_start' => FALSE,
																'backwpup_backups' => TRUE,
																'backwpup_backups_download' => FALSE,
																'backwpup_backups_delete' => FALSE,
																'backwpup_logs' => TRUE,
																'backwpup_logs_delete' => FALSE,
																'backwpup_settings' => FALSE,
														   ) );

		add_role( 'backwpup_helper', __( 'BackWPup jobs helper', 'backwpup' ), array(
																		 'backwpup' => TRUE,
																		 'backwpup_jobs' => TRUE,
																		 'backwpup_jobs_edit' => FALSE,
																		 'backwpup_jobs_start' => TRUE,
																		 'backwpup_backups' => TRUE,
																		 'backwpup_backups_download' => TRUE,
																		 'backwpup_backups_delete' => TRUE,
																		 'backwpup_logs' => TRUE,
																		 'backwpup_logs_delete' => TRUE,
																		 'backwpup_settings' => FALSE,
																	) );

		//add role to admin user if no one
		$users_backwpup = get_users( array( 'blog_id' => 1, 'role' => 'backwpup_admin' ) );
		if ( empty( $users_backwpup ) ) {
			/* @var WP_User $user */
			$users = get_users( array( 'blog_id' => 1, 'role' => 'administrator', 'fields' => 'all_with_meta' ) );
			foreach ( $users as $user ) {
				$user->add_role( 'backwpup_admin' );
			}
		}

		//update version
		update_site_option( 'backwpup_version', BackWPup::get_plugin_data( 'Version' ) );

	}

	/**
	 *
	 * Cleanup on Plugin deactivation
	 *
	 * @return void
	 */
	public static function deactivate() {

		wp_clear_scheduled_hook( 'backwpup_cron' );
		$activejobs = BackWPup_Option::get_job_ids( 'activetype', 'wpcron' );
		if ( ! empty( $activejobs ) ) {
			foreach ( $activejobs as $id ) {
				wp_clear_scheduled_hook( 'backwpup_cron', array( 'id' => $id ) );
			}
		}
		wp_clear_scheduled_hook( 'backwpup_check_cleanup' );
		//to reschedule on activation and so on
		update_site_option( 'backwpup_version', BackWPup::get_plugin_data( 'version' ) .'-inactive' );
	}


	private static function upgrade_from_version_two() {

		//load options
		$cfg = get_option( 'backwpup' ); //only exists in Version 2
		$jobs = get_option( 'backwpup_jobs' );

		//delete old options
		delete_option( 'backwpup' );
		delete_option( 'backwpup_jobs' );

		//add new option default structure and without auto load cache
		if ( ! is_multisite() )
			add_option( 'backwpup_jobs', array(), NULL, 'no' );

		//upgrade cfg
		//if old value switch it to new
		if ( ! empty( $cfg[ 'dirlogs' ] ) )
			$cfg[ 'logfolder' ] = $cfg[ 'dirlogs' ];
		if ( ! empty( $cfg[ 'httpauthpassword' ] ) ) {
			if ( preg_match('%^[a-zA-Z0-9/+]*={0,2}$%', $cfg[ 'httpauthpassword' ] ) )
				$cfg[ 'httpauthpassword' ] = base64_decode( $cfg[ 'httpauthpassword' ] );
			$cfg[ 'httpauthpassword' ] = BackWPup_Encryption::encrypt( $cfg[ 'httpauthpassword' ] );
		}
		// delete old not needed vars
		unset( $cfg[ 'dirtemp' ], $cfg[ 'dirlogs' ], $cfg[ 'logfilelist' ], $cfg[ 'jobscriptruntime' ], $cfg[ 'jobscriptruntimelong' ], $cfg[ 'last_activate' ], $cfg[ 'disablewpcron' ], $cfg[ 'phpzip' ], $cfg[ 'apicronservice' ], $cfg[ 'mailsndemail' ], $cfg[ 'mailsndname' ], $cfg[ 'mailmethod' ], $cfg[ 'mailsendmail' ], $cfg[ 'mailhost' ], $cfg[ 'mailpass' ], $cfg[ 'mailhostport' ], $cfg[ 'mailsecure' ], $cfg[ 'mailuser' ] );
		//save in options
		foreach ( $cfg as $cfgname => $cfgvalue )
			update_site_option( 'backwpup_cfg_' . $cfgname, $cfgvalue );

		//Put old jobs to new if exists
		foreach ( $jobs as $jobid => $jobvalue ) {
			//convert general settings
			if ( empty( $jobvalue[ 'jobid' ] ) )
				$jobvalue[ 'jobid' ] = $jobid;
			if ( empty( $jobvalue[ 'activated' ] ) )
				$jobvalue[ 'activetype' ] = '';
			else
				$jobvalue[ 'activetype' ] = 'wpcron';
			if ( ! isset( $jobvalue[ 'cronselect' ] ) && ! isset( $jobvalue[ 'cron' ] ) )
				$jobvalue[ 'cronselect' ] = 'basic';
			elseif ( ! isset( $jobvalue[ 'cronselect' ] ) && isset( $jobvalue[ 'cron' ] ) )
				$jobvalue[ 'cronselect' ] = 'advanced';
			$jobvalue[ 'backuptype' ]     = 'archive';
			$jobvalue[ 'type' ]           = explode( '+', $jobvalue[ 'type' ] ); //save as array
			foreach ( $jobvalue[ 'type' ] as $key => $type ) {
				if ( $type == 'DB' )
					$jobvalue[ 'type' ][ $key ] = 'DBDUMP';
				if ( $type == 'OPTIMIZE' )
					$jobvalue[ 'type' ][ $key ] = 'DBOPTIMIZE';
				if ( $type == 'CHECK' )
					$jobvalue[ 'type' ][ $key ] = 'DBCHECK';
				if ( $type == 'MAIL' )
					$jobvalue[ 'type' ][ $key ] = 'EMAIL';
			}
			$jobvalue[ 'archivename' ]    = $jobvalue[ 'fileprefix' ] . '%Y-%m-%d_%H-%i-%s';
			$jobvalue[ 'archiveformat' ] = $jobvalue[ 'fileformart' ];
			//convert active destinations
			$jobvalue[ 'destinations' ] = array();
			if ( ! empty( $jobvalue[ 'backupdir' ] ) and $jobvalue[ 'backupdir' ] != '/' )
				$jobvalue[ 'destinations' ][ ] = 'FOLDER';
			if ( ! empty( $jobvalue[ 'mailaddress' ] ) )
				$jobvalue[ 'destinations' ][ ] = 'MAIL';
			if ( ! empty( $jobvalue[ 'ftphost' ] ) and ! empty( $jobvalue[ 'ftpuser' ] ) and ! empty( $jobvalue[ 'ftppass' ] ) )
				$jobvalue[ 'destinations' ][ ] = 'FTP';
			if ( ! empty( $jobvalue[ 'dropetoken' ] ) and ! empty( $jobvalue[ 'dropesecret' ] ) )
				$jobvalue[ 'destinations' ][ ] = 'DROPBOX';
			if ( ! empty( $jobvalue[ 'sugarrefreshtoken' ] ) and ! empty( $jobvalue[ 'sugarroot' ] ) )
				$jobvalue[ 'destinations' ][ ] = 'SUGARSYNC';
			if ( ! empty( $jobvalue[ 'awsAccessKey' ] ) and ! empty( $jobvalue[ 'awsSecretKey' ] ) and ! empty( $jobvalue[ 'awsBucket' ] ) )
				$jobvalue[ 'destinations' ][ ] = 'S3';
			if ( ! empty( $jobvalue[ 'GStorageAccessKey' ] ) and ! empty( $jobvalue[ 'GStorageSecret' ] ) and ! empty( $jobvalue[ 'GStorageBucket' ] ) and !in_array( 'S3', $jobvalue[ 'destinations' ] ) )
				$jobvalue[ 'destinations' ][ ] = 'S3';
			if ( ! empty( $jobvalue[ 'rscUsername' ] ) and ! empty( $jobvalue[ 'rscAPIKey' ] ) and ! empty( $jobvalue[ 'rscContainer' ] ) )
				$jobvalue[ 'destinations' ][ ] = 'RSC';
			if ( ! empty( $jobvalue[ 'msazureHost' ] ) and ! empty( $jobvalue[ 'msazureAccName' ] ) and ! empty( $jobvalue[ 'msazureKey' ] ) and ! empty( $jobvalue[ 'msazureContainer' ] ) )
				$jobvalue[ 'destinations' ][ ] = 'MSAZURE';
			//convert dropbox
			$jobvalue[ 'dropboxtoken' ] = ''; //new app key are set must reauth
			$jobvalue[ 'dropboxsecret' ] = '';
			$jobvalue[ 'dropboxroot' ] = 'dropbox';
			$jobvalue[ 'dropboxmaxbackups' ] = $jobvalue[ 'dropemaxbackups' ];
			$jobvalue[ 'dropboxdir' ] = $jobvalue[ 'dropedir' ];
			unset( $jobvalue[ 'dropetoken' ], $jobvalue[ 'dropesecret' ], $jobvalue[ 'droperoot' ], $jobvalue[ 'dropemaxbackups' ], $jobvalue[ 'dropedir' ] );
			//convert amazon S3
			$jobvalue[ 's3accesskey' ] = $jobvalue[ 'awsAccessKey' ];
			$jobvalue[ 's3secretkey' ] = BackWPup_Encryption::encrypt( $jobvalue[ 'awsSecretKey' ] );
			$jobvalue[ 's3bucket' ] = $jobvalue[ 'awsBucket' ];
			//get aws region
			$jobvalue[ 's3region' ] = 'us-east-1';
			$jobvalue[ 's3base_url' ] = '';
			$jobvalue[ 's3storageclass' ] = !empty( $jobvalue[ 'awsrrs' ] ) ? 'REDUCED_REDUNDANCY' : '';
			$jobvalue[ 's3dir' ] = $jobvalue[ 'awsdir' ];
			$jobvalue[ 's3maxbackups' ] = $jobvalue[ 'awsmaxbackups' ];
			unset( $jobvalue[ 'awsAccessKey' ], $jobvalue[ 'awsSecretKey' ], $jobvalue[ 'awsBucket' ], $jobvalue[ 'awsrrs' ], $jobvalue[ 'awsdir' ], $jobvalue[ 'awsmaxbackups' ] );
			//convert google storage
			$jobvalue[ 's3accesskey' ] = $jobvalue[ 'GStorageAccessKey' ];
			$jobvalue[ 's3secretkey' ] = BackWPup_Encryption::encrypt( $jobvalue[ 'GStorageSecret' ] );
			$jobvalue[ 's3bucket' ] = $jobvalue[ 'GStorageBucket' ];
			$jobvalue[ 's3region' ] = 'google-storage';
			$jobvalue[ 's3base_url' ] = '';
			$jobvalue[ 's3ssencrypt' ] = '';
			$jobvalue[ 's3dir' ] = $jobvalue[ 'GStoragedir' ];
			$jobvalue[ 's3maxbackups' ] = $jobvalue[ 'GStoragemaxbackups' ];
			unset( $jobvalue[ 'GStorageAccessKey' ], $jobvalue[ 'GStorageSecret' ], $jobvalue[ 'GStorageBucket' ], $jobvalue[ 'GStoragedir' ], $jobvalue[ 'GStoragemaxbackups' ] );
			//convert MS Azure storage
			$jobvalue[ 'msazureaccname' ] = $jobvalue[ 'msazureAccName' ];
			$jobvalue[ 'msazurekey' ] =  BackWPup_Encryption::encrypt( $jobvalue[ 'msazureKey' ] );
			$jobvalue[ 'msazurecontainer' ] = $jobvalue[ 'msazureContainer' ];
			unset( $jobvalue[ 'msazureHost' ], $jobvalue[ 'msazureAccName' ], $jobvalue[ 'msazureKey' ], $jobvalue[ 'msazureContainer' ] );
			//convert FTP
			if ( preg_match('%^[a-zA-Z0-9/+]*={0,2}$%', $jobvalue[ 'ftppass' ]) )
				$jobvalue[ 'ftppass' ] = base64_decode( $jobvalue[ 'ftppass' ] );
			$jobvalue[ 'ftppass' ] = BackWPup_Encryption::encrypt( $jobvalue[ 'ftppass' ] );
			if ( ! empty( $jobvalue[ 'ftphost' ] ) && strstr( $jobvalue[ 'ftphost' ], ':' ) )
				list( $jobvalue[ 'ftphost' ], $jobvalue[ 'ftphostport' ] ) = explode( ':', $jobvalue[ 'ftphost' ], 2 );
			//convert Sugarsync
			//convert Mail
			$jobvalue[ 'emailaddress' ]  = $jobvalue[ 'mailaddress' ];
			$jobvalue[ 'emailefilesize' ] = $jobvalue[ 'mailefilesize' ];
			unset( $jobvalue[ 'mailaddress' ], $jobvalue[ 'mailefilesize' ] );
			//convert RSC
			$jobvalue[ 'rscusername' ] = $jobvalue[ 'rscUsername' ];
			$jobvalue[ 'rscapikey' ] = $jobvalue[ 'rscAPIKey' ];
			$jobvalue[ 'rsccontainer' ] = $jobvalue[ 'rscContainer' ];
			//convert jobtype DB Dump
			$jobvalue[ 'dbdumpexclude' ] = $jobvalue[ 'dbexclude' ];
			unset( $jobvalue[ 'dbexclude' ], $jobvalue['dbshortinsert'] );
			//convert jobtype DBDUMP, DBCHECK, DBOPTIMIZE
			$jobvalue[ 'dbcheckrepair' ] = TRUE;
			unset( $jobvalue[ 'maintenance' ] );
			//convert jobtype wpexport
			//convert jobtype file
			$excludes = array();
			foreach ( $jobvalue[ 'backuprootexcludedirs' ] as  $folder ) {
				$excludes[] = basename( $folder );
			}
			$jobvalue[ 'backuprootexcludedirs' ] = $excludes;
			$excludes = array();
			foreach ( $jobvalue[ 'backupcontentexcludedirs' ] as  $folder ) {
				$excludes[] = basename( $folder );
			}
			$jobvalue[ 'backupcontentexcludedirs' ] = $excludes;
			$excludes = array();
			foreach ( $jobvalue[ 'backuppluginsexcludedirs' ] as  $folder ) {
				$excludes[] = basename( $folder );
			}
			$jobvalue[ 'backuppluginsexcludedirs'  ]= $excludes;
			$excludes = array();
			foreach ( $jobvalue[ 'backupthemesexcludedirs' ] as  $folder ) {
				$excludes[] = basename( $folder );
			}
			$jobvalue[ 'backupthemesexcludedirs' ] = $excludes;
			$excludes = array();
			foreach ( $jobvalue[ 'backupuploadsexcludedirs' ] as  $folder ) {
				$excludes[] = basename( $folder );
			}
			$jobvalue[ 'backupuploadsexcludedirs' ] = $excludes;
			//delete not longer needed
			unset( $jobvalue[ 'cronnextrun' ], $jobvalue[ 'fileprefix' ], $jobvalue[ 'fileformart' ], $jobvalue[ 'scheduleintervaltype' ], $jobvalue[ 'scheduleintervalteimes' ], $jobvalue[ 'scheduleinterval' ], $jobvalue[ 'dropemail' ], $jobvalue[ 'dropepass' ], $jobvalue[ 'dropesignmethod' ] );
			//save in options
			foreach ( $jobvalue as $jobvaluename => $jobvaluevalue )
				BackWPup_Option::update( $jobvalue[ 'jobid' ], $jobvaluename, $jobvaluevalue );
		}
		set_site_transient( 'backwpup_upgrade_from_version_two', TRUE, 3600*24*7);
	}
}
