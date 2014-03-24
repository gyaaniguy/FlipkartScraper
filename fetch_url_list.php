<?php

/**
 * Find link for next page and return it.
 * @param $qp
 * @param $pattern
 * @return bool href of next link if found or halse if not found
 */
function getNextLink($qp, $pattern) {
    $nextPageClass = $qp->top()->find('.pager-next');
    if (count($nextPageClass) > 0) {
        return $nextPageClass->eq(0)->attr('href');
    }
    return false;
}

/**
 * strip the pid from a href string.
 * @param $href
 * @return string
 */
function get_pid_from_href($href){
    global $db_connection ;
    parse_str(parse_url($href, PHP_URL_QUERY), $hrefArray) ;
    if (isset($hrefArray['pid'])){
        return $db_connection->escape_string($hrefArray['pid']) ;
    }
    else {
        return '' ;
    }
}

/**
 * Helper function -> get the innertext of a querypath var.
 * @param $qpPart
 * @param $class
 * @return string
 */
function get_text_from_qp($qpPart, $class) {
    if ($titleDiv = branch_get_class($qpPart, $class)) {
        return $titleDiv->eq(0)->text();
    } else {
        return 'Field not Detected';
    }
}

/**
 * find element with specified class and return it .
 * @param $div
 * @param $class
 * @return bool OR qp element
 */
function branch_get_class($div, $class) {
    $title = $div->branch()->find($class);
    if (count($title) > 0) {
        return $title;
    } else {
        return false;
    }

}

function remove_special_chars($string) {
    return preg_replace('/[^a-zA-z0-9\,\- ]/', '', $string);
}

/**
 * get category id if it exists , else create it and return its id.
 * @param $cat
 * @return mixed
 */
function get_cat_id($cat) {
    global $db_connection;
    global $errors_glob ;
    $all_sql = "SELECT id  FROM categories WHERE name= '" . $cat . "' LIMIT 1 ";
    $resultex = $db_connection->query($all_sql);
    assert('$resultex') ;
        $row = $resultex->fetch_assoc();
        if ($row) {
            return $row['id'];
        } else {
            $catArr = explode(' ', $cat) ;
            $slug = strtolower($catArr[0]) ;
            $all_sql = "INSERT INTO categories SET name= '" . $cat . "', slug= '" . $slug . "'";
            $resultex = $db_connection->query($all_sql);
            return $db_connection->insert_id;

        }
}

/**
 * STEP 2, after the scraping process, analyzes the data looking for price changes and - populates the 15percent, 15amount and json columns..
 * DONE remove all products that were last updated a while ago and are not available (this is to keep the db size down) ;
 */
function get_available_products() {
    global $db_connection;
    global $time;
    global $errors_glob ;
    $all_sql = "SELECT * FROM products where available= '1'";
    $resultset = $db_connection->query($all_sql);
    if ($resultset) {
        while ($row = $resultset->fetch_assoc()) {
            $sql = 'SELECT * FROM productpricetime  WHERE product_id = "' . $row['id'] . '"';
            $result = $db_connection->query($sql);
            if ($result) {
                $pricesArr = array();
                while ($priceRow = $result->fetch_assoc()) {
                    $pricesArr[$priceRow['insertion_timestamp']] = $priceRow;
                }
                $time_keys = array_keys($pricesArr) ;
                arsort($time_keys, SORT_NUMERIC | SORT_DESC);
                $max = max($time_keys);
                $min = $max - (3600 * 24 * ( DAYS + .5 ) ); //add by .5 for that extra half day
                $min1 = $max - (1.5 * 3600 * 24); //1.5 = 1 + .5

                $changeArr = get_amount($pricesArr, $time_keys, $max, $min);
                $json = get_json($pricesArr, $time_keys, $max, $min);

                $changeArr1 = get_amount($pricesArr, $time_keys, $max, $min1);
                $json1 = get_json($pricesArr, $time_keys, $max, $min1);
                $insertSql = 'UPDATE products SET latest_price = "'.$pricesArr[$max]['price'].'", 15percent="'.$changeArr['percent'].'", 15amount="'.$changeArr['amount'].'", json= "'.htmlentities($json)
                    .'", 1percent="'.$changeArr1['percent'].'", 1amount="'.$changeArr1['amount'].'", 1json= "'.htmlentities($json1).'" WHERE id= "'.$row['id'].'"' ;

                $updateSet = $db_connection->query($insertSql);
                assert($updateSet) ;
                if (!$updateSet){

                }

            }

        }
    }
}

/**
 * legacy function can be cleaned up
 * @param $all_existing_products
 */
function remove_products_in_array($all_existing_products){
    remove_product($all_existing_products) ;

}

/**
 * Calculate change in amount & percentage as compared to last fetch
 * @param $pricesArr
 * @param $time_keys
 * @param $max
 * @param $min
 * @return array
 */
function get_amount($pricesArr, $time_keys, $max, $min) {
    $change = array();
    foreach ($time_keys as $time) {
        if ($time < $min) {
            break;
        } else {
            $mini = $time;
        }
    }
    //TODO if !isset($mini) {create new $mini} $max is the latest time and $min earliest time
    $latestPrice  = filter_int($pricesArr[$max]['price'])  ;
    $earliestPrice = filter_int($pricesArr[$mini]['price']) ;
    $change['amount'] = $latestPrice - $earliestPrice ;
    $highestAmount = max($latestPrice,$earliestPrice) ;
    if ($change['amount']){
        $change['percent'] = intval(($change['amount'] / $highestAmount) * 100) ;
    }
    else {
        $change['percent'] = 0 ;
    }

    return $change;
}

/**
 * Create json from existing data for google charts and store in db.
 * @param $pricesArr
 * @param $time_keys
 * @param $max
 * @param $min
 * @return string
 */
function get_json($pricesArr, $time_keys, $max, $min) {
    $array = array() ;
    $array['cols'][] = array( 'label' => 'Date','type' => 'string');
    $array['cols'][] = array('label' => 'Price' , 'type' => 'number');


    $rows = array() ;
    foreach ($pricesArr as $price) {
        if  ($price['insertion_timestamp']> $min ){
            $dateString = date( 'D',(int) $price['insertion_timestamp'] )   ;
            $array['rows'][]['c'] = array(
                array('v' =>  $dateString),
                array('v' => filter_int($price['price']) )
            );
        }
    }
    return json_encode($array);

}
function filter_int($text){
    return (int)filter_var($text,  FILTER_SANITIZE_NUMBER_INT) ;
}

/**
 * main function that parses results table into mysql.
 * @param $qp
 * @param $cat_id
 * @return bool
 */
function    parse_for_table($qp, $cat_id) {
    global $db_connection;
    global $time;
    global $time_within_limit;
    global $current_new_products ;
    global $errors_glob ;
    $search_results = $qp->find('.search-results li');
    $sqlStart = 'INSERT into products ( product_name ,url, image , rating_value , rating_number ,  subtitle , offer, category_id , pid ) values ';
    $priceSql = "INSERT INTO productpricetime ( product_id, insertion_timestamp,price) values ";
    foreach ($search_results as $result) {
        $include_product = true;
        if ($negative = branch_get_class($result, '.text-highlight-negative')) {
            $include_product = false;
            $stop_going_forward = true;
        }

        if ($urlLink = branch_get_class($result, 'a')) {
            $href = $urlLink->eq(0)->attr('href') ;
            $pid = get_pid_from_href($href) ;
            $href = $db_connection->escape_string($href);
        }
        else {
            $href ='' ;
            $pid ='' ;
        }


        if ($imgTag = branch_get_class($result, '.product-image-wrapper img')) {
            $imgSrc  = $imgTag->eq(1)->attr('src') ;
            $imageName = basename($imgSrc) ;
            $imgPath = curl_file($imgSrc, $imageName) ;
            $image = $db_connection->escape_string($imgPath);
        } else {
            $image = '';
        }
        if ($titleTag = branch_get_class($result, '.product-title')) {
            $name = $db_connection->escape_string(clean_string($titleTag->eq(0)->text()));
        } else {
            $name = '';
        }
        if ($ratingNumTag = branch_get_class($result, '.num-ratings')) {
            $rating_number = $db_connection->escape_string($ratingNumTag->eq(0)->text());
        } else {
            $rating_number = 0;
        }
        if ($subtitleTag = branch_get_class($result, '.product-subtitle')) {
            $subtitle = $db_connection->escape_string(clean_string($subtitleTag->eq(0)->text()));
        } else {
            $subtitle = '';
        }
        if ($ratingTag = branch_get_class($result, '.rating')) {
            $rating_value = $db_connection->escape_string($ratingTag->eq(0)->attr('style'));
        } else {
            $rating_value = '';
        }
        if ($offerTag = branch_get_class($result, '.product-offer')) {
            $offer = $db_connection->escape_string(clean_string($offerTag->eq(0)->text()));
        } else {
            $offer = '';
        }
        if ($finalTag = branch_get_class($result, '.price-fsp')) {
            $final_price = $db_connection->escape_string($finalTag->eq(0)->text());
        } else {
            $final_price = '';
        }
        if (in_array($name . $subtitle.$pid, $current_new_products)){
            $include_product = false ;
        }
        else {
            $current_new_products[] = $name . $subtitle .$pid ;
        }
        if ($name && $include_product) {
            // if product exists in products table , get its id
            $normalized_href = href_normalize($href) ;
            if ($rowId = product_exists($normalized_href,$pid )) {
                //set to available //TODO remove pid its just for updating db one time.
                $UpdateSql = 'UPDATE products SET available = "1", offer="'.$offer.'", pid="'.$pid.'", rating_value = "'.$rating_value.'" WHERE id= "'.$rowId.'"' ;
//                echo $UpdateSql ;
                $updateSet = $db_connection->query($UpdateSql);
                assert('$updateSet') ;

            } else {
                // add product
                $sql = $sqlStart . " ( '$name' , '$href', '$image', '$rating_value', '$rating_number', '$subtitle', '$offer' ,'$cat_id ','$pid' ) ";
                $resultset = $db_connection->query($sql);
                if ($resultset) {
                    $rowId = $db_connection->insert_id;
                }

            }
            //add price info if time is within limit . Theresw a better word for it!!
            $existsQ = "SELECT EXISTS(SELECT 1 FROM productpricetime WHERE product_id ='$rowId' AND insertion_timestamp > " . $time_within_limit . " LIMIT 1)  ";
            $resultex = $db_connection->query($existsQ);
            if ($final_price ) {
                $row = $resultex->fetch_row();
                if ($row[0]) {

                } else {
                    $priceSql .= " ( '$rowId','$time','$final_price' ),	";
                }
            }


        }
        elseif ($name && !$include_product) {
            //NOT NEEDED AS PRODUCTS WILL BE REMOVED IN THE SECOND STEP..
//            if ( ($name)){
//                remove_product($name) ;
//            }
        }
    }
    $priceSql = substr($priceSql, 0, -2);
    //TODO IMPORTANT UNCOMMENT BELOW LINE IN NEXT COMMIT.
    $resultset = $db_connection->query($priceSql);

    if (isset($stop_going_forward) && $stop_going_forward) {
        return true;
    } else {
        return false;
    }
}

/**
 * remove product if it doesn't exist on flipkart.com
 * @param $all_existing_products
 */
function  remove_product($all_existing_products) {
    global $db_connection;
    global $errors_glob;
    $sql = "UPDATE products SET available = 0 where id  IN (";
    foreach ($all_existing_products as $product) {
        $sql .= "'".$product['id']."' , " ;
    }
    $sql = substr($sql,0,-2) ;
    $sql .= ' ) ' ;
    $resultex = $db_connection->query($sql);
    assert('$resultex') ;

}

/**
 * Check if product exists in all_existing_products_array, if it does remove it from the array and return its id.
 * @param $name
 * @param $pid
 * @return bool
 */
function product_exists($name,$pid  ) {
    global $all_existing_products;
    if (array_key_exists($name, $all_existing_products) ) {
//        file_put_contents('key_exists.txt', $name.PHP_EOL, FILE_APPEND) ;
        $id = $all_existing_products[$name]['id'];
        //unset so at the end of the program we will have only the products that were in the db but not on flipkart in this array.
        unset($all_existing_products[$name]);
        return $id;

    }
    return false;
}

/**
 * @return array|bool all existing products are returned in an array
 */
function    get_all_existing_products() {
    global $db_connection ;
    global $all_existing_products ;
    global $errors_glob ;
    $all_sql = "SELECT id, product_name,subtitle, available,url FROM products";
    $resultex = $db_connection->query($all_sql);
    if ($resultex) {
        while ($row = $resultex->fetch_assoc()) {

            $normalized_href = href_normalize($row['url']) ;
//            $key = $db_connection->escape_string($row['product_name']) . $db_connection->escape_string($row['subtitle'] ) ;  // escape so that the key mnatches with the escaped $name and subttile.
            $key = $db_connection->escape_string($normalized_href ) ;  // escape so that the key mnatches with the escaped $name and subttile.

            if (isset($all_existing_products[$key])){
//                send_mail_using_gmail('Duplicate URL key- '.$key . ' Line- '.__LINE__) ;
            }
//            assert('!isset($all_existing_products[$key])') ;
            $all_existing_products[$key ] = $row;

        }
        return $all_existing_products;
    }
    return false;
}

/**
 * remove stuff from href that changes to get the unique params for that product.
 * @param $href
 * @return string
 */
function href_normalize($href){
    $parts = parse_url($href);

    $queryParams = array();
    parse_str($parts['query'], $queryParams);
    unset($queryParams['pageNum']);
    unset($queryParams['otracker']);
    $queryString = http_build_query($queryParams);
    $url = $parts['path'] . '?' . $queryString;
    return $url ;

}

function clean_string($strin) {
    $strin = preg_replace('#\r|\n|\s+#', ' ', $strin);
    return trim($strin);
}
