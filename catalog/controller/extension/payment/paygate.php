<?php
/*
 * Copyright (c) 2018 PayGate (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

class ControllerExtensionPaymentPaygate extends Controller
{

    public function index()
    {
        unset( $this->session->data['REFERENCE'] );

        $data['text_loading']   = $this->language->get( 'text_loading' );
        $data['button_confirm'] = $this->language->get( 'button_confirm' );
        $data['text_loading']   = $this->language->get( 'text_loading' );
        $data['continue']       = $this->language->get( 'payment_url' );

        $this->load->model( 'checkout/order' );

        $order_info = $this->model_checkout_order->getOrder( $this->session->data['order_id'] );

        if ( $order_info ) {
            $preAmount = number_format( $order_info['total'], 2, '', '' );
            $dateTime  = new DateTime();
            $time      = $dateTime->format( 'YmdHis' );
            $paygateID = filter_var( $this->config->get( 'payment_paygate_merchant_id' ), FILTER_SANITIZE_STRING );
            $reference = filter_var( $order_info['order_id'], FILTER_SANITIZE_STRING );
            $amount    = filter_var( $preAmount, FILTER_SANITIZE_NUMBER_INT );
            $currency  = '';

            if ( $this->config->get( 'config_currency' ) != '' ) {
                $currency = filter_var( $this->config->get( 'config_currency' ), FILTER_SANITIZE_STRING );
            } else {
                $currency = filter_var( $this->currency->getCode(), FILTER_SANITIZE_STRING );
            }

            $returnUrl       = filter_var( $this->url->link( 'extension/payment/paygate/paygate_return', '', true ), FILTER_SANITIZE_URL );
            $transDate       = filter_var( date( 'Y-m-d H:i:s' ), FILTER_SANITIZE_STRING );
            $locale          = filter_var( 'en', FILTER_SANITIZE_STRING );
            $country         = filter_var( $order_info['payment_iso_code_3'], FILTER_SANITIZE_STRING );
            $email           = filter_var( $order_info['email'], FILTER_SANITIZE_EMAIL );
            // Check if email empty due to some custom themes displaying this on the same page
            $email = empty($email) ? $this->config->get('config_email') : $email;
            $payMethod       = '';
            $payMethodDetail = '';
            $notifyUrl       = filter_var( $this->url->link( 'extension/payment/paygate/notify_handler', '', true ), FILTER_SANITIZE_URL );
            $userField1      = $order_info['order_id'];
            $userField2      = '';
            $userField3      = 'opencart-v3.0.2';
            $doVault         = '';
            $vaultID         = '';
            $encryption_key  = $this->config->get( 'payment_paygate_merchant_key' );
            $checksum_source = $paygateID . $reference . $amount . $currency . $returnUrl . $transDate;

            if ( $locale ) {
                $checksum_source .= $locale;
            }
            if ( $country ) {
                $checksum_source .= $country;
            }
            if ( $email ) {
                $checksum_source .= $email;
            }
            if ( $payMethod ) {
                $checksum_source .= $payMethod;
            }
            if ( $payMethodDetail ) {
                $checksum_source .= $payMethodDetail;
            }
            if ( $notifyUrl ) {
                $checksum_source .= $notifyUrl;
            }
            if ( $userField1 ) {
                $checksum_source .= $userField1;
            }
            if ( $userField2 ) {
                $checksum_source .= $userField2;
            }
            if ( $userField3 ) {
                $checksum_source .= $userField3;
            }
            if ( $doVault != '' ) {
                $checksum_source .= $doVault;
            }
            if ( $vaultID != '' ) {
                $checksum_source .= $vaultID;
            }

            $checksum_source .= $encryption_key;
            $checksum     = md5( $checksum_source );
            $initiateData = array(
                'PAYGATE_ID'        => $paygateID,
                'REFERENCE'         => $reference,
                'AMOUNT'            => $amount,
                'CURRENCY'          => $currency,
                'RETURN_URL'        => $returnUrl,
                'TRANSACTION_DATE'  => $transDate,
                'LOCALE'            => $locale,
                'COUNTRY'           => $country,
                'EMAIL'             => $email,
                'PAY_METHOD'        => $payMethod,
                'PAY_METHOD_DETAIL' => $payMethodDetail,
                'NOTIFY_URL'        => $notifyUrl,
                'USER1'             => $userField1,
                'USER2'             => $userField2,
                'USER3'             => $userField3,
                'VAULT'             => $doVault,
                'VAULT_ID'          => $vaultID,
                'CHECKSUM'          => $checksum,
            );
            $CHECKSUM       = null;
            $PAY_REQUEST_ID = null;
            $fields_string  = '';

            // Url-ify the data for the POST
            foreach ( $initiateData as $key => $value ) {
                $fields_string .= $key . '=' . $value . '&';
            }

            rtrim( $fields_string, '&' );

            // Open connection
            $ch = curl_init();

            // Set the url, number of POST vars, POST data
            curl_setopt( $ch, CURLOPT_POST, 1 );
            curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
            curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, false );
            curl_setopt( $ch, CURLOPT_URL, 'https://secure.paygate.co.za/payweb3/initiate.trans' );
            curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
            curl_setopt( $ch, CURLOPT_POST, count( $initiateData ) );
            curl_setopt( $ch, CURLOPT_POSTFIELDS, $fields_string );

            // Execute post
            $result = curl_exec( $ch );

            // Close connection
            curl_close( $ch );

            parse_str( $result );

            if ( isset( $ERROR ) ) {
                print_r( 'Error trying to initiate a transaction, paygate error code: ' . $ERROR . '. Log support ticket to <a href="' . $this->url->link( 'information/contact' ) . '">shop owner</a>' );

                die();
            }

            $data['CHECKSUM']       = $CHECKSUM;
            $data['PAY_REQUEST_ID'] = $PAY_REQUEST_ID;

            $this->session->data['REFERENCE'] = $time;
        } else {
            print_r( 'Order could not be found, order_id: ' . $this->session->data['order_id'] . '. Log support ticket to <a href="' . $this->url->link( 'information/contact' ) . '">shop owner</a>' );
            die();
        }

        return $this->load->view( 'extension/payment/paygate', $data );
    }

    public function paygate_return()
    {
        $this->load->language( 'checkout/paygate' );
        $statusDesc = '';
        $status     = '';

        if ( isset( $this->session->data['order_id'] ) ) {
            $this->cart->clear();

            // Add to activity log
            $this->load->model( 'account/activity' );

            if ( $this->customer->isLogged() ) {
                $activity_data = array(
                    'customer_id' => $this->customer->getId(),
                    'name'        => $this->customer->getFirstName() . ' ' . $this->customer->getLastName(),
                    'order_id'    => $this->session->data['order_id'],
                );
                $this->model_account_activity->addActivity( 'order_account', $activity_data );
            } else {
                $activity_data = array(
                    'name'     => $this->session->data['guest']['firstname'] . ' ' . $this->session->data['guest']['lastname'],
                    'order_id' => $this->session->data['order_id'],
                );
                $this->model_account_activity->addActivity( 'order_guest', $activity_data );
            }

            $paygateID      = filter_var( $this->config->get( 'payment_paygate_merchant_id' ), FILTER_SANITIZE_STRING );
            $pay_request_id = filter_var( $_POST['PAY_REQUEST_ID'], FILTER_SANITIZE_STRING );
            $reference      = filter_var( $this->session->data['order_id'], FILTER_SANITIZE_STRING );
            $encryption_key = $this->config->get( 'payment_paygate_merchant_key' );
            $checksum       = md5( $paygateID . $pay_request_id . $reference . $encryption_key );
            $queryData      = array(
                'PAYGATE_ID'     => $paygateID,
                'PAY_REQUEST_ID' => $pay_request_id,
                'REFERENCE'      => $reference,
                'CHECKSUM'       => $checksum,
            );
            $TRANSACTION_STATUS = null;
            $PAY_METHOD_DETAIL  = null;
            $fields_string      = null;

            // Url-ify the data for the POST
            foreach ( $queryData as $key => $value ) {
                $fields_string .= $key . '=' . $value . '&';
            }

            rtrim( $fields_string, '&' );

            // Open connection
            $ch = curl_init();

            // Set the url, number of POST vars, POST data
            curl_setopt( $ch, CURLOPT_POST, 1 );
            curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
            curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, false );
            curl_setopt( $ch, CURLOPT_URL, 'https://secure.paygate.co.za/payweb3/query.trans' );
            curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
            curl_setopt( $ch, CURLOPT_POST, count( $queryData ) );
            curl_setopt( $ch, CURLOPT_POSTFIELDS, $fields_string );

            unset( $this->session->data['REFERENCE'] );

            // Execute post
            $result = curl_exec( $ch );

            // Close connection
            curl_close( $ch );
            parse_str( $result );
            $pay_method_desc = '';

            if ( isset( $PAY_METHOD_DETAIL ) && $PAY_METHOD_DETAIL != '' ) {
                $pay_method_desc = ', using a payment method of ' . $PAY_METHOD_DETAIL;
            }

            $orderStatusId  = '7';
            $resultsComment = '';

            // Mapping pg transactions status with open card statuses
            if ( isset( $TRANSACTION_STATUS ) ) {
                $status = 'ok';

                if ( $TRANSACTION_STATUS == 0 ) {
                    $orderStatusId = 1;
                    $statusDesc    = 'pending';
                } else if ( $TRANSACTION_STATUS == 1 ) {
                    $orderStatusId = $this->config->get( 'payment_paygate_success_order_status_id' );
                    $statusDesc    = 'approved';
                } else if ( $TRANSACTION_STATUS == 2 ) {
                    $orderStatusId = $this->config->get( 'payment_paygate_failed_order_status_id' );
                    $statusDesc    = 'declined';
                } else if ( $TRANSACTION_STATUS == 4 ) {
                    $orderStatusId = $this->config->get( 'payment_paygate_cancelled_order_status_id' );
                    $statusDesc    = 'cancelled';
                }

                $resultsComment = "Returned from PayGate with a status of " . $statusDesc . $pay_method_desc;
            } else {
                $orderStatusId  = 1;
                $statusDesc     = 'pending';
                $resultsComment = 'Transaction status verification failed. Please contact the shop owner to confirm transaction status.';
            }

            $this->load->model( 'checkout/order' );
            $this->model_checkout_order->addOrderHistory( $this->session->data['order_id'], $orderStatusId, $resultsComment, true );
            unset( $this->session->data['shipping_method'] );
            unset( $this->session->data['shipping_methods'] );
            unset( $this->session->data['payment_method'] );
            unset( $this->session->data['payment_methods'] );
            unset( $this->session->data['guest'] );
            unset( $this->session->data['comment'] );
            unset( $this->session->data['order_id'] );
            unset( $this->session->data['coupon'] );
            unset( $this->session->data['reward'] );
            unset( $this->session->data['voucher'] );
            unset( $this->session->data['vouchers'] );
            unset( $this->session->data['totals'] );
        }

        if ( $status == 'ok' ) {
            $data['heading_title'] = sprintf( $this->language->get( 'heading_title' ), $statusDesc );
            $this->document->setTitle( $data['heading_title'] );
        } else {
            $data['heading_title'] = sprintf( 'Transaction status verification failed. Please contact the shop owner to confirm transaction status.' );
            $this->document->setTitle( $data['heading_title'] );
        }

        $data['breadcrumbs']   = array();
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get( 'text_home' ),
            'href' => $this->url->link( 'common/home' ),
        );

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get( 'text_basket' ),
            'href' => $this->url->link( 'checkout/cart' ),
        );

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get( 'text_checkout' ),
            'href' => $this->url->link( 'checkout/checkout', '', 'SSL' ),
        );

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get( 'text_success' ),
            'href' => $this->url->link( 'checkout/success' ),
        );

        if ( $this->customer->isLogged() ) {
            $data['text_message'] = sprintf( $this->language->get( 'text_customer' ), $this->url->link( 'account/account', '', 'SSL' ), $this->url->link( 'account/order', '', 'SSL' ), $this->url->link( 'account/download', '', 'SSL' ), $this->url->link( 'information/contact' ) );
        } else {
            $data['text_message'] = sprintf( $this->language->get( 'text_guest' ), $this->url->link( 'information/contact' ) );
        }

        $data['button_continue'] = $this->language->get( 'button_continue' );
        $data['continue']        = $this->url->link( 'common/home' );
        $data['column_left']     = $this->load->controller( 'common/column_left' );
        $data['column_right']    = $this->load->controller( 'common/column_right' );
        $data['content_top']     = $this->load->controller( 'common/content_top' );
        $data['content_bottom']  = $this->load->controller( 'common/content_bottom' );
        $data['footer']          = $this->load->controller( 'common/footer' );
        $data['header']          = $this->load->controller( 'common/header' );

        $this->response->setOutput( $this->load->view( 'common/success', $data ) );
    }

    public function notify_handler()
    {
        // Notify PayGate that information has been received
        echo 'OK';

        $errors = false;
        if ( isset( $ERROR ) ) {
            $errors = true;
        }

        $transaction_status = '';
        $order_id           = '';
        $pay_method_detail  = '';
        $pay_method_desc    = '';
        $checkSumParams     = '';
        $notify_checksum    = '';
        $post_data          = '';

        if ( !$errors ) {
            foreach ( $_POST as $key => $val ) {
                if ( $key == 'PAYGATE_ID' ) {
                    $checkSumParams .= $this->config->get( 'payment_paygate_merchant_id' );
                }

                if ( $key != 'CHECKSUM' && $key != 'PAYGATE_ID' ) {
                    $checkSumParams .= $val;
                }

                if ( $key == 'CHECKSUM' ) {
                    $notify_checksum = $val;
                }

                if ( $key == 'TRANSACTION_STATUS' ) {
                    $transaction_status = $val;
                }

                if ( $key == 'USER1' ) {
                    $order_id = $val;
                }

                if ( $key == 'PAY_METHOD_DETAIL' ) {
                    $pay_method_desc = ', using a payment method of ' . $val;
                }
            }

            $checkSumParams .= $this->config->get( 'payment_paygate_merchant_key' );
            $checkSumParams = md5( $checkSumParams );
            if ( $checkSumParams != $notify_checksum ) {
                $errors = true;
            }

            $orderStatusId = 7;

            if ( !$errors ) {
                if ( $transaction_status == 0 ) {
                    $orderStatusId = 1;
                    $statusDesc    = 'pending';
                } else if ( $transaction_status == 1 ) {
                    $orderStatusId = 2;
                    $statusDesc    = 'approved';
                } else if ( $transaction_status == 2 ) {
                    $orderStatusId = 8;
                    $statusDesc    = 'declined';
                } else if ( $transaction_status == 4 ) {
                    $orderStatusId = 7;
                    $statusDesc    = 'cancelled';
                }

                $resultsComment = "Notify from PayGate with a status of " . $statusDesc . $pay_method_desc;
                $this->load->model( 'checkout/order' );
                $this->model_checkout_order->addOrderHistory( $order_id, $orderStatusId, $resultsComment, true );
            }
        }
    }

    public function confirm()
    {
        if ( $this->session->data['payment_method']['code'] == 'paygate' ) {
            $this->load->model( 'checkout/order' );
            $comment = 'Redirected to PayGate';
            $this->model_checkout_order->addOrderHistory( $this->session->data['order_id'], $this->config->get( 'payment_paygate_order_status_id' ), $comment, true );
        }
    }

    public function before_redirect()
    {
        $json = array();

        if ( $this->session->data['payment_method']['code'] == 'paygate' ) {
            $this->load->model( 'checkout/order' );
            $comment = 'Before Redirected to PayGate';
            $this->model_checkout_order->addOrderHistory( $this->session->data['order_id'], 1 );
            $json['answer'] = 'success';
        }

        $this->response->addHeader( 'Content-Type: application/json' );
        $this->response->setOutput( json_encode( $json ) );
    }
}
