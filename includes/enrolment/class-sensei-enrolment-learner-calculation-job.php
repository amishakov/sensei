<?php
/**
 * File containing the class Sensei_Enrolment_Learner_Calculation_Job.
 *
 * @package sensei
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Sensei_Enrolment_Learner_Calculation_Job is responsible for running jobs of user enrolment calculations. It is set
 * up to run once after a Sensei version upgrade or when Sensei_Course_Enrolment_Manager::get_site_salt() is updated.
 */
class Sensei_Enrolment_Learner_Calculation_Job implements Sensei_Background_Job_Interface {
	const NAME                      = 'sensei_calculate_learner_enrolments';
	const OPTION_TRACK_LAST_USER_ID = 'sensei_calculate_learner_enrolments_last_user_id';

	/**
	 * Number of users for each job run.
	 *
	 * @var integer
	 */
	private $batch_size;

	/**
	 * Whether the job is complete.
	 *
	 * @var bool
	 */
	private $is_complete = false;

	/**
	 * Sensei_Enrolment_Learner_Calculation_Job constructor.
	 *
	 * @param integer $batch_size The scheduler's batch size.
	 */
	public function __construct( $batch_size ) {
		$this->batch_size = $batch_size;
	}

	/**
	 * Get the action name for the scheduled job.
	 *
	 * @return string
	 */
	public function get_name() {
		return self::NAME;
	}

	/**
	 * Get the arguments to run with the job.
	 *
	 * @return array
	 */
	public function get_args() {
		return [];
	}

	/**
	 * Run the job.
	 */
	public function run() {
		// We do not want to store negative enrolment results in this bulk background job.
		Sensei_Course_Enrolment::set_store_negative_enrolment_results( false );

		$enrolment_manager = Sensei_Course_Enrolment_Manager::instance();

		$meta_query = [
			'relation' => 'OR',
			[
				'key'     => Sensei_Course_Enrolment_Manager::LEARNER_CALCULATION_META_NAME,
				'value'   => $enrolment_manager->get_enrolment_calculation_version(),
				'compare' => '!=',
			],
			[
				'key'     => Sensei_Course_Enrolment_Manager::LEARNER_CALCULATION_META_NAME,
				'compare' => 'NOT EXISTS',
			],
		];

		$user_args = [
			'fields'     => 'ID',
			'number'     => $this->batch_size,
			'order'      => 'ASC',
			'orderby'    => 'ID',
			'meta_query' => $meta_query, // phpcs:ignore  WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The results are limited by the batch size.
		];

		add_action( 'pre_user_query', [ $this, 'modify_user_query_add_user_id' ] );
		$user_ids = get_users( $user_args );
		remove_action( 'pre_user_query', [ $this, 'modify_user_query_add_user_id' ] );

		if ( empty( $user_ids ) ) {
			$this->is_complete = true;
			delete_option( self::OPTION_TRACK_LAST_USER_ID );

			return;
		}

		foreach ( $user_ids as $user_id ) {
			Sensei_Course_Enrolment_Manager::instance()->recalculate_enrolments( $user_id );

			$this->set_last_user_id( $user_id );
		}
	}

	/**
	 * After the job runs, check to see if it needs to be re-queued for the next batch.
	 *
	 * @return bool
	 */
	public function is_complete() {
		return $this->is_complete;
	}

	/**
	 * Modify user query to add the user ID check.
	 *
	 * @access private
	 *
	 * @param WP_User_Query $user_query User query to modify.
	 */
	public function modify_user_query_add_user_id( WP_User_Query $user_query ) {
		global $wpdb;

		$user_query->query_where .= $wpdb->prepare( ' AND ID>%d', $this->get_last_user_id() );
	}

	/**
	 * Set the last calculated user ID.
	 *
	 * @param int $user_id User ID.
	 */
	public function set_last_user_id( $user_id ) {
		update_option( self::OPTION_TRACK_LAST_USER_ID, (int) $user_id, false );
	}

	/**
	 * Get the last user ID that was calculated.
	 *
	 * @return int
	 */
	public function get_last_user_id() {
		return (int) get_option( self::OPTION_TRACK_LAST_USER_ID, 0 );
	}

	/**
	 * Get whether the job is currently in progress.
	 *
	 * @return bool
	 */
	public function in_progress() {
		return false !== get_option( self::OPTION_TRACK_LAST_USER_ID, false );
	}
}
