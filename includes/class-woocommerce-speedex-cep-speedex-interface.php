<?php
if (!defined('ABSPATH'))
    exit;

class WC_Speedex_CEP_Admin {
	private static $_this;
	
	public function __construct() {
		self::$_this = $this;
		
		add_action( 'admin_enqueue_scripts', array( $this, 'load_admin_scripts' ) );

		add_action( 'wp_ajax_wc_speedex_cep_cancel_bol', array( $this, 'cancelBol' ) );
		add_action( 'wp_ajax_wc_speedex_cep_get_bol_pdf', array( $this, 'getBolPdf' ) );
		add_action( 'wp_ajax_wc_speedex_cep_manually_create_bol', array( $this, 'manuallyCreateBol' ) );
		add_action( 'wp_ajax_wc_speedex_cep_get_bol_summary_pdf', array( $this, 'getBolSummaryPdf' ) );
		add_action( 'wp_ajax_wc_speedex_cep_validate_connection', array( $this, 'validateConnection' ) );
		
		add_action( 'add_meta_boxes', array( $this, 'speedex_add_meta_boxes' ) );
		
		$sel_statuses = get_option( 'order-statuses' );
		if ( !empty( $sel_statuses ) && is_array( $sel_statuses ) ) {
			foreach ( $sel_statuses as $status ){
				add_action( 'woocommerce_order_status_' . $status, array( $this, 'autoCreateBol' ) );
			}
		}
	}

	/**
	 * Centralized SOAP Client helper
	 */
	private function get_soap_client() {
		$url = ( get_option( 'testmode' ) != 1 ) ? 'https://spdxws.gr/accesspoint.asmx' : 'https://devspdxws.gr/accesspoint.asmx';
		$options = array(
			'cache_wsdl' => WSDL_CACHE_BOTH, // Optimization: Use cache for speed
			'encoding'   => 'UTF-8',
			'exceptions' => true,
			'trace'      => 1
		);
		return new SoapClient( $url . "?WSDL", $options );
	}
	
	
	function load_admin_scripts( $hook )
	{
		// Only load scripts on the relevant WooCommerce order pages
		$allowed_hooks = array( 'post.php', 'post-new.php', 'woocommerce_page_wc-orders' );
		if ( ! in_array( $hook, $allowed_hooks ) ) {
			return;
		}

		wp_enqueue_script( 'wc_speedex_cep_loadingoverlay_lib_js', plugins_url( 'assets/libs/loadingoverlay/loadingoverlay.min.js' , dirname( __FILE__ ) ), array( 'jquery' ) );
		wp_enqueue_script( 'wc_speedex_cep_goodpopup_lib_js', plugins_url( 'assets/libs/jquery.goodpopup/js/script.min.js' , dirname( __FILE__ ) ), array( 'jquery' ) );
		wp_enqueue_script( 'wc_speedex_cep_admin_order_page_script', plugins_url( 'assets/js/admin.js' , dirname( __FILE__ ) ), array( 'jquery' ), '1.0.0', true );
				
		$localized_vars = array(
			'ajaxurl'                   		=> admin_url( 'admin-ajax.php' ),
			'ajaxAdminCancelBOLNonce'   		=> wp_create_nonce( '_wc_speedex_cep_cancel_bol_nonce' ),
			'ajaxAdminGetBOLPdfNonce'  			=> wp_create_nonce( '_wc_speedex_cep_get_bol_pdf' ),
			'ajaxAdminManuallyCreateBolNonce'   => wp_create_nonce( '_wc_speedex_cep_manually_create_bol' ),
			'ajaxAdminGetBolSummaryPdfNonce'   	=> wp_create_nonce( '_wc_speedex_cep_get_bol_summary_pdf' ),
			'invalidPdfError'					=> __( 'Download failed. An error occured.', 'woocommerce-speedex-cep' ),
			'ajaxErrorMessage'					=> __( 'A network error occured.', 'woocommerce-speedex-cep' ),
			'ajaxGetBolSummaryPdfError'			=> __( 'Voucher list failed to download because the pdf is invalid.','woocommerce-speedex-cep' ),
		);
		
		wp_localize_script( 'wc_speedex_cep_admin_order_page_script', 'wc_speedex_cep_local', $localized_vars );
		wp_enqueue_style( 'wc_speedex_cep_goodpopup_lib_css', plugins_url( 'assets/libs/jquery.goodpopup/css/style.min.css' , dirname( __FILE__ ) ) );
		wp_enqueue_style( 'wc_speedex_cep_admin_style', plugins_url( 'assets/css/admin.css', dirname( __FILE__ ) ) );
	}
	
	function autoCreateBol( $order_id )
	{
		$order = wc_get_order( $order_id );
		if ( ! $order ) return;
		
		$speedex_post_meta = $order->get_meta( '_speedex_voucher_code', true );
		if ( empty( $speedex_post_meta ) ) {
			$this->createBol( $order_id );
		}
	}
	
	function getBolSummaryPdf( $beginDate = 0, $endDate = 0 )
	{
		check_ajax_referer( '_wc_speedex_cep_get_bol_summary_pdf', 'ajaxAdminGetBolSummaryPdfNonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'woocommerce-speedex-cep' ) ) );
		}
		
		try
		{	
			$soap_client = $this->get_soap_client();
			$beginDate = empty( $beginDate ) ? mktime( 0, 0, 0, date( 'n' ), date( 'j' ) ) : $beginDate;
			$endDate = empty( $endDate ) ?  time() : $endDate;

			$session_id_response = $this->getSession( $soap_client );
			if ( $session_id_response['status'] === 'success' ) {
				$session_id = $session_id_response['session_id'];
			}
			else {
				wp_send_json( array(
					'status' => 'fail',
					'message' => $session_id_response['message'],
					'base64string' => ''
				));
			}
			
			$get_bol_summary_pdf_response = $soap_client->GetBOLSummaryPdf( array( "sessionID" => $session_id, "beginDate" => $beginDate, "endDate" => $endDate));
			
			// Always destroy session
			$this->destroySession( $session_id, $soap_client );

			if ( $get_bol_summary_pdf_response->returnCode != 1 ) {
				wp_send_json( array( 
					'status' => 'fail',
					'message' => sprintf( __( 'Voucher summary list pdf failed to download. Error Code: %s Error Message: %s', 'woocommerce-speedex-cep' ), $get_bol_summary_pdf_response->returnCode, $get_bol_summary_pdf_response->returnMessage ),
					'base64string' => ''
				));
			} else {
				$base64_pdf_string = $get_bol_summary_pdf_response->GetBOLSummaryPdfResult;
				wp_send_json( array( 
					'status' => 'success',
					'message' => '',
					'base64string' => base64_encode( $base64_pdf_string )
				));
			}
		} catch ( Exception $e ) {
			wp_send_json( array( 
					'status' => 'fail',
					'message' => $e->getMessage(),
					'base64string' => ''
				));	
		}
	}
	
	function manuallyCreateBol()
	{
		check_ajax_referer( '_wc_speedex_cep_manually_create_bol', 'ajaxAdminManuallyCreateBolNonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'woocommerce-speedex-cep' ) ) );
		}

		$order_id = isset( $_POST['post_id_number'] ) ? absint( $_POST['post_id_number'] ) : 0;
		if ( ! $order_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order ID.', 'woocommerce-speedex-cep' ) ) );
		}

		$create_bol_result = $this->createBol( $order_id );
		if ( $create_bol_result['status'] === 'success' ) {
			$response = array(
				'status' => 'success',
				'message' => $create_bol_result['message'].' '.__( 'The page will refresh shortly.', 'woocommerce-speedex-cep' )
			);
		}
		else { 
			$response = array(
				'status' => 'fail',
				'message' => $create_bol_result['message']
			);
		}	
		wp_send_json( $response );
	}
	
	function getBolPdf()
	{
		check_ajax_referer( '_wc_speedex_cep_get_bol_pdf', 'ajaxAdminGetBOLPdfNonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'woocommerce-speedex-cep' ) ) );
		}

		$voucher_code = isset( $_POST['voucher_code'] ) ? sanitize_text_field( $_POST['voucher_code'] ) : '';
		if ( ! $voucher_code ) {
			wp_send_json_error( array( 'message' => __( 'Invalid voucher code.', 'woocommerce-speedex-cep' ) ) );
		}

		try
		{	
			$soap_client = $this->get_soap_client();
			$session_id_response = $this->getSession( $soap_client );
			
			if ( $session_id_response['status'] === 'success' ) {
				$session_id = $session_id_response['session_id'];
			}
			else {
				wp_send_json( array(
					'status' => 'fail',
					'message' => $session_id_response['message'],
					'base64array' => array()
				));
			}

			$get_bol_pdf_params = array( 
				"sessionID" => $session_id, 
				"voucherIDs" => array( "string" => array( $voucher_code ) ), 
				"perVoucher" => true, 
				"paperType" => 1
			);
			
			$get_bol_pdf_response = $soap_client->GetBOLPdf( $get_bol_pdf_params );
			
			$this->destroySession( $session_id, $soap_client );

			if ( $get_bol_pdf_response->returnCode != 1 ) {
				wp_send_json( array( 
					'status' => 'fail',
					'message' => sprintf( __( 'Voucher\'s pdf file failed to download. Error Code: %s Error Message: %s', 'woocommerce-speedex-cep' ), $get_bol_pdf_response->returnCode, $get_bol_pdf_response->returnMessage ),
					'base64array' => array()
				));
			} else {
				$base64array = array();
				$results = $get_bol_pdf_response->GetBOLPdfResult;
				
				if ( isset( $results->Voucher ) ) {
					$vouchers = is_array( $results->Voucher ) ? $results->Voucher : array( $results->Voucher );
					foreach( $vouchers as $v )
					{
						if ( isset( $v->pdf ) ) {
							$base64array[] = base64_encode( $v->pdf );
						}
					}
				}
				
				if ( empty( $base64array ) ) {
					wp_send_json( array( 
						'status' => 'fail',
						'message' => __( 'No PDF content found for this voucher code.', 'woocommerce-speedex-cep' ),
						'base64array' => array()
					));
				}
				else {
					wp_send_json( array( 
						'status' => 'success',
						'message' => '',
						'base64array' => $base64array
					));
				}
			}
		} catch ( Exception $e ) {
			wp_send_json( array( 
					'status' => 'fail',
					'message' => $e->getMessage(),
					'base64array' => array()
				));
		}
	}

	function cancelBol()
	{
		check_ajax_referer( '_wc_speedex_cep_cancel_bol_nonce', 'ajaxAdminCancelBOLNonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'woocommerce-speedex-cep' ) ) );
		}

		$order_id = isset( $_POST['post_id_number'] ) ? absint( $_POST['post_id_number'] ) : 0;
		$voucher_code = isset( $_POST['voucher_code'] ) ? sanitize_text_field( $_POST['voucher_code'] ) : '';

		if ( ! $order_id || ! $voucher_code ) {
			wp_send_json( array(
				'status' => 'fail',
				'message' => __( 'Cannot cancel the voucher. Invalid request data.', 'woocommerce-speedex-cep' )
			));
		}
		
		$order = wc_get_order( $order_id );
		if( $order ) {
			try
			{	
				$soap_client = $this->get_soap_client();
				$session_id_response = $this->getSession( $soap_client );

				if ( $session_id_response['status'] === 'success' ) {
					$session_id = $session_id_response['session_id'];
				}
				else {
					wp_send_json( array (
						'status' => 'fail',
						'message' => $session_id_response['message']
					) );
				}
				
				$cancel_bol_response = $soap_client->CancelBOL( array( "sessionID" => $session_id, "voucherID" => $voucher_code ) );
				$this->destroySession( $session_id, $soap_client );

				if ( $cancel_bol_response->returnCode != 1 ) {
					if( $cancel_bol_response->returnCode == 603 ) {
						// Shipment doesn't exist at Speedex, but we should remove it locally
						$this->remove_local_voucher_meta( $order, $voucher_code );

						wp_send_json( array(
							'status' => 'fail',
							'message' => __( 'Speedex respond that shipment does not exist. This voucher got deleted from this order. The page will refresh shortly.', 'woocommerce-speedex-cep' )
						));
					} else {
						throw new Exception( sprintf( __( 'Could not cancel Voucher. Error Code: %s Error Message: %s', 'woocommerce-speedex-cep' ), $cancel_bol_response->returnCode, $cancel_bol_response->returnMessage ) );
					}		
				} else {
					$this->remove_local_voucher_meta( $order, $voucher_code );

					wp_send_json( array(
						'status' => 'success',
						'message' => __( 'This voucher is successfully deleted. The page will refresh shortly.', 'woocommerce-speedex-cep' )
					));
				}
			} catch ( Exception $e ) {
				wp_send_json( array(
					'status' => 'fail',
					'message' => $e->getMessage() . ' ' . __( 'The page will refresh shortly.', 'woocommerce-speedex-cep' )
				));
			}
		}
	}

	/**
	 * Helper to remove voucher meta correctly for HPOS/Traditional
	 */
	private function remove_local_voucher_meta( $order, $voucher_code ) {
		$all_meta = $order->get_meta_data();
		foreach ( $all_meta as $meta ) {
			if ( $meta->key === '_speedex_voucher_code' && $meta->value === $voucher_code ) {
				$order->delete_meta_data_by_mid( $meta->id );
				break;
			}
		}
		$order->save();
	}

	function splitCommentsToArray( $comments ) {
		
		$arrayWords = explode( ' ', $comments );

		$maxLineLength = 40;

		$currentLength = 0;
		$index = 1;

		if( !empty( $arrayWords ) ) {
			foreach ( $arrayWords as $word ) {
				$wordLength = strlen( $word ) + 1;

				if ( ( $currentLength + $wordLength ) <= $maxLineLength ) {
					$arrayOutput[ $index ] .= $word . ' ';

					$currentLength += $wordLength;
				} else {
					$index += 1;

					$currentLength = $wordLength;

					$arrayOutput[ $index ] = $word . ' ';
				}
			}
		} else {
			for( $i = 0; $i <= 2; $i++ ) {
				$splittedString = substr( $comments, $i * 40, ( $i + 1 ) * 40 );
				$arrayOutput[ $i + 1 ] = !empty( $splittedString ) ? $splittedString : '';
			}
		}
		
		return $arrayOutput;
	}
	
	function createBol( $order_id )
	{
		$order = wc_get_order( $order_id );
		if ( !$order ) { 
			return array( 
				'status' => 'fail',
				'message' => __( 'This post is not a valid shop order.', 'woocommerce-speedex-cep' )
			);
		}
		
		$sel_methods = get_option( 'methods' );
		foreach( $order->get_items( 'shipping' ) as $item_id => $shipping_item_obj ){
			$shipping_method_id = $shipping_item_obj->get_method_id(); // The method ID
			if( $shipping_method_id ){
				$shipping_method_id = ( strpos( $shipping_method_id, ':' ) === false ) ? $shipping_method_id : substr( $shipping_method_id, 0, strpos( $shipping_method_id, ':' ) );
				break;
			}	
		}
		
		if( !in_array($shipping_method_id, $sel_methods)){
			return array( 
				'status' => 'fail',
				'message' => __( 'Voucher creation is not available for the selected shipping method of this order.', 'woocommerce-speedex-cep' )
			);
		}
		
		try {
			$soap_client = $this->get_soap_client();
		} catch ( Exception $e ) {
			return array(
				'status' => 'fail',
				'message' => __( 'Failed to connect to Speedex API: ', 'woocommerce-speedex-cep' ) . $e->getMessage()
			);
		}
		
		$comments = isset( $_POST['voucher_comments'] ) ? sanitize_text_field( trim( $_POST['voucher_comments'] ) ) : '';
		//$comments = $_POST['voucher_comments'] ;
		
		$address_1 = $order->get_shipping_address_1();
		$address_2 = $order->get_shipping_address_2();
		if ( ! empty( $address_2 ) ) {
			$address_1 .= ' ' . $address_2;
		}

		// Define BOL object strictly according to wsdl/xml schema order
		// Use credentials from settings strictly.
		$customer_id = get_option( 'customer_id' );
		$agreement_id = get_option( 'agreement_id' );

		if ( empty( $customer_id ) || empty( $agreement_id ) ) {
			// If not in settings, we can't proceed. Fallback only if absolutely necessary for legacy tests, 
			// but better to ask user to fill settings.
			if ( get_option( 'testmode' ) == 1 ) {
				$customer_id = !empty($customer_id) ? $customer_id : 'DEMO';
				$agreement_id = !empty($agreement_id) ? $agreement_id : '001';
			} else {
				return array(
					'status' => 'fail',
					'message' => __( 'Please enter Customer ID and Agreement ID in Speedex settings.', 'woocommerce-speedex-cep' )
				);
			}
		}

		// Define BOL object strictly according to wsdl/xml schema order
		$bol_object_array = array(
			'MasterId' => '',
			'Members' => null, 
			'Shipping_Agent' => '',
			'No' => '',
			'_cust_Flag' => (int)0,
			'BranchBankCode' => '',
			'Comments_2853_1' => sprintf( __( 'Order ID: %s','woocommerce-speedex-cep' ), $order->get_id() ),
			'Comments_2853_2' => '',
			'Comments_2853_3' => '',
			'Items' => (int)1,
			'Paratiriseis_2853_1' => !empty( mb_substr( $comments, 0, 65, 'UTF-8') ) ? mb_substr( $comments, 0, 65, 'UTF-8') : '',
			'Paratiriseis_2853_2' => !empty( mb_substr( $comments, 65, 65, 'UTF-8') ) ? mb_substr( $comments, 65, 65, 'UTF-8') : '',
			'Paratiriseis_2853_3' => !empty( mb_substr( $comments, 130, 65, 'UTF-8') ) ? mb_substr( $comments, 130, 65, 'UTF-8') : '',
			'PayCode_Flag' => (int)1,
			'Pod_Amount_Cash' => ( strcmp( $order->get_payment_method(), 'cod' ) == 0 ) ? (double)$order->get_total() : 0.0,
			'Pod_Amount_Description' => 'M',
			'RCV_Addr1' => $address_1,
			'RCV_Zip_Code' => $order->get_shipping_postcode(),
			'RCV_City' => $order->get_shipping_city(), 
			'RCV_Country' => $order->get_shipping_country(),
			'RCV_Name' => $order->get_shipping_first_name().' '.$order->get_shipping_last_name(),
			'RCV_Tel1' => $order->get_billing_phone(),
			'Saturday_Delivery' => (int)0,
			'Security_Value' => (int)0,
			'Snd_agreement_id' => strval( $agreement_id ),
			'SND_Customer_Id' => strval( $customer_id ),
			'Time_Limit' => '',
			'voucher_code' => '',
			'Voucher_Weight' => (double)0.1,
		);
		
		if( empty( $bol_object_array['RCV_Name'] ) ) {
			$mandatory_fields_empty[] = __( 'Consignee Name', 'woocommerce-speedex-cep' );
		}
		if( empty( $bol_object_array['RCV_Addr1'] ) ) {
			$mandatory_fields_empty[] = __( 'Consignee Address', 'woocommerce-speedex-cep' );
		}
		if( empty( $bol_object_array['RCV_Zip_Code'] ) ) {
			$mandatory_fields_empty[] = __( 'Consignee Zip Code', 'woocommerce-speedex-cep' );
		}
		if( empty( $bol_object_array['RCV_City'] ) ) {
			$mandatory_fields_empty[] = __( 'Consignee City or Town', 'woocommerce-speedex-cep' );
		}
		if( empty( $bol_object_array['RCV_Country'] ) ) {
			$mandatory_fields_empty[] = __( 'Consignee Country', 'woocommerce-speedex-cep' );
		}
		if( empty( $bol_object_array['RCV_Tel1'] ) ) {
			$mandatory_fields_empty[] = __( 'Consignee Telephone', 'woocommerce-speedex-cep' );
		}
		
		if( !empty( $mandatory_fields_empty ) ) {
			$mandatory_fields_length = sizeof( $mandatory_fields_empty );
			return array(
				'status' => 'fail',
				'message' => sprintf( _n( '%s is a mandatory field.', '%s are mandatory fields', $mandatory_fields_length, 'woocommerce-speedex-cep' ), implode( ', ', $mandatory_fields_empty ) )
			);
		}
		
		try
		{
			$session_id_response = $this->getSession( $soap_client );
			if ( $session_id_response['status'] === 'success' ) {
				$session_id = $session_id_response['session_id'];
			}
			else {
				return array(
					'status' => 'fail',
					'message' => $session_id_response['message']
				);
			}
			
			// Use associative key 'BOL' inside 'inListPod' array to ensure correct XML wrapping
			$create_bol_response = $soap_client->CreateBOL( array( 
				'sessionID' => $session_id, 
				'inListPod' => array( 'BOL' => $bol_object_array ), 
				'tableFlag' => 3 
			) );
			if ( $create_bol_response->returnCode != 1 ) {
				$detailed_error = '';
				
				// Check statusList for specific errors
				if ( isset( $create_bol_response->statusList ) ) {
					$statusList = $create_bol_response->statusList;
					if ( is_object( $statusList ) && isset( $statusList->string ) ) {
						$detailed_error .= ' ' . $statusList->string;
					} elseif ( is_array( $statusList ) ) {
						// Fallback if it's an array or other structure
						$detailed_error .= ' ' . json_encode( $statusList );
					}
				}

				// Check outListPod for item-specific errors
				if ( !empty( $create_bol_response->outListPod ) ) {
					$pods = $create_bol_response->outListPod;
					if ( ! is_array( $pods ) ) {
						$pods = array( $pods );
					}
					foreach ( $pods as $pod ) {
						if ( isset( $pod->returnMessage ) ) {
							$detailed_error .= ' ' . $pod->returnMessage;
						}
					}
				}

				$this->destroySession( $session_id, $soap_client );
				return array(
					'status' => 'fail',
					'message' => sprintf( __( 'Voucher creation failed. Error Code: %s Error Message: %s%s', 'woocommerce-speedex-cep' ), $create_bol_response->returnCode, $create_bol_response->returnMessage, $detailed_error )
				);
			} else {
				$plural = 0;
				if ( isset( $create_bol_response->outListPod->BOL ) ) {
					$pods = $create_bol_response->outListPod->BOL;
					if ( ! is_array( $pods ) ) {
						$pods = array( $pods );
					}
					
					foreach( $pods as $pod )
					{
						if ( isset( $pod->voucher_code ) && !empty( $pod->voucher_code ) ) {
							$plural++;
							$v_code = stripslashes( $pod->voucher_code );
							
							// Use WC_Order method for meta data to ensure HPOS compatibility
							$order->add_meta_data( '_speedex_voucher_code', $v_code, false );
							
							if ( (int)get_option( 'sync_tracking' ) == 1 ) {
								$order->update_meta_data( '_shipping_tracking_number', $v_code );
							}

							$order->save();
							
							$order->add_order_note( sprintf ( __( 'The newly created voucher code is: %s', 'woocommerce-speedex-cep' ), $v_code ));
						}
					}
				}
				$this->destroySession( $session_id, $soap_client );
				
				if ( $plural > 0 ) {
					return array(
						'status' => 'success',
						'message' => _n( 'Voucher creation succeeded.', 'Vouchers creation succeeded.', $plural , 'woocommerce-speedex-cep' )
					);
				} else {
					return array(
						'status' => 'fail',
						'message' => __( 'Voucher creation failed: No voucher code was returned by Speedex API.', 'woocommerce-speedex-cep' )
					);
				}
			}
		}
		catch ( Exception $e)
		{
			return array(
				'status' => 'fail',
				'message' => $e->getMessage()
			);
		}
	}

	function getSession( $soap_client )
	{		
		$username = get_option ( 'username' );
		$password = get_option ( 'password' );
		if( !$username && !$password ) {
			return array (
				'status' => 'fail',
				'message' => sprintf ( __( 'Username or password are not valid. Please update them with valid ones in the <a href="%s">settings</a> page.', 'woocommerce-speedex-cep' ) , admin_url( 'admin.php?page=wc-speedex-cep-settings' ) ), // TO-DO settings link
				'session_id' => '',
			);
		}
		try { 
			$authentication_result = $soap_client->CreateSession( array( 'username' => $username, 'password' => $password ) );
			if ( $authentication_result->returnCode != 1 ) {
				if ( $authentication_result->returnCode == 100 ) {
					return array (
						'status' => 'fail',
						'message' => sprintf ( __( 'Username or password are not valid. Please update them with valid ones in the <a href="%s">settings</a> page.', 'woocommerce-speedex-cep' ), admin_url( 'admin.php?page=wc-speedex-cep-settings' ) ),
						'session_id' => '',
					);
				} else {
					return array(
						'status' => 'fail',
						'message' => sprintf( __( 'An error occured while requesting for session ID. Error Code: %s, Error Message: %s', 'woocommerce-speedex-cep' ), $authentication_result->returnCode, $authentication_result->returnMessage ),
						'session_id' => '',
					); 
				}
				
				
			}else
			{
				return array(
					'status' => 'success',
					'message' => __( 'Request for session id was successful', 'woocommerce-speedex-cep' ),
					'session_id' => $session_ID = $authentication_result->sessionId,
				); 
			}
		}
		catch( SoapFault $soap_fault ) {
			return array(
					'status' => 'fail',
					'message' => $soap_fault->getMessage(),
					'session_id' => '',
				); 
		}	
	}

	function validateConnection()
	{
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'woocommerce-speedex-cep' ) ) );
		}

		$username = isset( $_POST['username'] ) ? sanitize_text_field( $_POST['username'] ) : '';
		$password = isset( $_POST['password'] ) ? sanitize_text_field( $_POST['password'] ) : '';
		$testmode = isset( $_POST['testmode'] ) ? (int) $_POST['testmode'] : 0;

		if ( empty( $username ) || empty( $password ) ) {
			wp_send_json_error( array( 'message' => __( 'Username and password are required.', 'woocommerce-speedex-cep' ) ) );
		}

		// Temporarily override options for the session check
		add_filter( 'pre_option_username', function() use ( $username ) { return $username; } );
		add_filter( 'pre_option_password', function() use ( $password ) { return $password; } );
		add_filter( 'pre_option_testmode', function() use ( $testmode ) { return $testmode; } );

		try {
			$soap_client = $this->get_soap_client();
			$session_id_response = $this->getSession( $soap_client );

			if ( $session_id_response['status'] === 'success' ) {
				$this->destroySession( $session_id_response['session_id'], $soap_client );
				wp_send_json_success( array( 'message' => __( 'Connection successful.', 'woocommerce-speedex-cep' ) ) );
			} else {
				wp_send_json_error( array( 'message' => $session_id_response['message'] ) );
			}
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	function destroySession( $session_id, $soap_client_obj )
	{
		try { 
			$authentication_result = $soap_client_obj->DestroySession( array( 'sessionID' => $session_id ) );
			if ( $authentication_result->returnCode != 1 ) {
				return false;
			}else{
				return true;
			}
		}
		catch( SoapFault $soap_fault ) {
			return false;
		}
	}


	function speedex_add_meta_boxes()
	{
		$screens = array( 
			'shop_order', 
			'edit-shop_order', 
			'woocommerce_page_wc-orders', 
			'woocommerce_page_wc-orders-edit',
			'wc-orders' 
		);
		foreach ( $screens as $screen ) {
			add_meta_box( 'speedex_order_fields', __( 'Speedex','woocommerce-speedex-cep' ), array( $this, 'speedex_voucher_code_management_widget' ), $screen, 'side', 'high' );
		}
	}


	function speedex_voucher_code_management_widget( $post_or_order )
	{		
		if ( $post_or_order instanceof WP_Post ) {
			$order_id = $post_or_order->ID;
		} elseif ( is_numeric( $post_or_order ) ) {
			$order_id = $post_or_order;
		} elseif ( is_object( $post_or_order ) && method_exists( $post_or_order, 'get_id' ) ) {
			$order_id = $post_or_order->get_id();
		} else {
			global $post;
			$order_id = isset($post->ID) ? $post->ID : 0;
		}

		if ( !$order_id ) {
			return;
		}

		$order_ob = wc_get_order( $order_id );
		if ( ! $order_ob ) {
			return;
		}
		
		$meta_fields_data = get_post_meta( $order_id, '_speedex_voucher_code', false );
		if ( empty( $meta_fields_data ) && is_callable( array( $order_ob, 'get_meta' ) ) ) {
			$meta_fields_data = $order_ob->get_meta( '_speedex_voucher_code', false );
		}
		
		/* debug info removed */

		$shipping_method_id = '';
		$sel_methods = get_option('methods');
		foreach( $order_ob->get_items( 'shipping' ) as $item_id => $shipping_item_obj ){
			$shipping_method_id = $shipping_item_obj->get_method_id(); // The method ID
			if( $shipping_method_id ){
				$shipping_method_id = ( strpos( $shipping_method_id, ':' ) === false ) ? $shipping_method_id : substr( $shipping_method_id, 0, strpos( $shipping_method_id, ':' ) );
				break;
			}	
		}
		
		if( !$sel_methods || !in_array( $shipping_method_id, $sel_methods )){
			echo esc_html__( 'Voucher creation is not available for the selected shipping method of this order.', 'woocommerce-speedex-cep' );
		}
		else {
			if( empty( $meta_fields_data ) ) {
				?><div id="wc_speedex_cep_vouchers">
					<ul class="totals">
						<li>
							<label  style="display:block; clear:both; font-weight:bold;"><?php echo esc_html__( 'No vouchers exists for this order.','woocommerce-speedex-cep' ); ?></label>
						</li>
						<li>
							<input type="button" class="button generate-items manually_create_voucher" value="<?php echo esc_attr__( 'Create a New Voucher', 'woocommerce-speedex-cep' ); ?>"  name="manually_create_voucher" />
						</li>
						<?php $this->advancedBolCreationOptionsHTML(); ?>
					</ul>
				</div><?php		
			}
			else
			{
				?>
				<div id="wc_speedex_cep_vouchers">
				<?php
				foreach( $meta_fields_data as $voucher_code )
				{
					if ( is_object( $voucher_code ) && isset( $voucher_code->value ) ) {
						$voucher_code = $voucher_code->value;
					}
					
					if ( empty( $voucher_code ) ) continue;
					?>
					<div class="wc_speedex_cep_voucher_box">
						<ul class="totals">
							<li>
								<label style="display:block; clear:both; font-weight:bold;"><?php echo sprintf( esc_html__( 'Voucher: %s', 'woocommerce-speedex-cep' ), esc_html( $voucher_code ) ); ?></label>
							</li>
							<li>
								<input id="woocommerce-speedex-cep-cancel-voucher" type="button" class="button button-primary cancel_voucher" data-voucher-code="<?php echo esc_attr( $voucher_code ); ?>" value="<?php echo esc_attr__( 'Cancel Voucher', 'woocommerce-speedex-cep' ); ?>" name="cancel_voucher" />
							</li>
							<li>
								<input id="woocommerce-speedex-cep-download-voucher" type="button" class="button generate-items download_voucher" data-voucher-code="<?php echo esc_attr( $voucher_code ); ?>" value="<?php echo esc_attr__( 'Download Voucher', 'woocommerce-speedex-cep' ); ?>"  name="download_voucher" />
							</li>
						</ul>
					</div>
					<?php
				}?>
				<input id="woocommerce-speedex-cep-manually-create-voucher" type="button" class="button generate-items manually_create_voucher" style="margin-bottom: 6px;" value="<?php echo esc_attr__( 'Create a New Voucher', 'woocommerce-speedex-cep' ); ?>"  name="manually_create_voucher" />
				<?php $this->advancedBolCreationOptionsHTML(); ?>
				</div><?php
			}
		}
	}
	
	function advancedBolCreationOptionsHTML() {
		?>
		<input id="bol-creation-advanced-options" type="checkbox" name="bol-creation-advanced-options"/><label for="bol-creation-advanced-options"><?php _e( 'Add comments to the new voucher', 'woocommerce-speedex-cep' ); ?></label>
		<textarea id="advanced-bol-creation-comments" class="advanced-bol-creation-comments" name="advanced-bol-creation-comments" maxlength="195" style="width: 100%; margin-top: 10px; display:none;" placeholder="<?php _e( 'Add the comment you wish to be included in the voucher...', 'woocommerce-speedex-cep' ); ?>"></textarea>

		<?php
	}

}//end class

new WC_Speedex_CEP_Admin();