<?php
/*
 +--------------------------------------------------------------------------+
 | Copyright IT Bliss LLC (c) 2012-2013                                     |
 +--------------------------------------------------------------------------+
 | This program is free software: you can redistribute it and/or modify     |
 | it under the terms of the GNU Affero General Public License as published |
 | by the Free Software Foundation, either version 3 of the License, or     |
 | (at your option) any later version.                                      |
 |                                                                          |
 | This program is distributed in the hope that it will be useful,          |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of           |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the            |
 | GNU Affero General Public License for more details.                      |
 |                                                                          |
 | You should have received a copy of the GNU Affero General Public License |
 | along with this program.  If not, see <http://www.gnu.org/licenses/>.    |
 +--------------------------------------------------------------------------+
*/

use libphonenumber\PhoneNumberUtil;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\NumberParseException;

require_once 'libphonenumber/PhoneNumberUtil.php';

class CRM_Utils_Normalize {
  CONST NORMALIZE_PREFERENCES_NAME = 'Normalize Preferences';

  private static $_singleton = NULL;

  private $_settings;
  private $_country;
  private $_nameFields;
  private $_phoneFields;
  private $_addressFields;


  static function singleton() {
    if (!self::$_singleton) {
      self::$_singleton = new CRM_Utils_Normalize();
    }
    return self::$_singleton;
  }

  /**
   * Construct a normalizer
   */
  public function __construct() {
    $this->_settings = $this->getSettings();
    // Get the default country information for phone/zip formatting
    $config = CRM_Core_Config::singleton();
    $this->_country = $config->defaultContactCountry();

    $this->_nameFields = array('first_name', 'middle_name', 'last_name', 'organization_name', 'household_name', 'legal_name', 'nick_name');
    $this->_phoneFields = array('phone');
    $this->_addressFields = array('city', 'postal_code');
  }

  /**
   * Returns normalizer settings
   */
  static function getSettings($name = NULL) {
    return CRM_Core_BAO_Setting::getItem(CRM_Utils_Normalize::NORMALIZE_PREFERENCES_NAME, $name);
  }

  static function setSetting($value, $name) {
    CRM_Core_BAO_Setting::setItem($value, CRM_Utils_Normalize::NORMALIZE_PREFERENCES_NAME, $name);
  }

  /**
   * Normalizes a name according to International rules - as much as we can
   *
   * For Individual names:
   * - put all in lowercase, then capitalize first letter of each word (with exceptions)
   * - examples:
   *   jean-pierre DE castignac => Jean-Pierre de Castignac
   *
   * For Organization names:
   * - capitalize all characters before dots & know organization statuses
   * - examples:
   *   IT bliss, l.l.c. => IT Bliss, L.L.C.
   *   IT bliss, LLC    => IT Bliss, LLC
   *   frank and sons moving co => Frank and Sons Moving CO
   *
   * @param $contact
   *   Name that needs to be normalized
   */
  function normalize_contact(&$contact) {
    $handles = array(
      'de', 'des', 'la', // France
      'da', 'den', 'der', 'ten', 'ter', 'van', // Neederlands
      'von', // Germany
      'et', 'and', 'und', // For company names
      'dos', 'das', 'do', 'du',
      "s" // skip apostrophe s
    );

    // These will be small
    $orgHandles = array(
      'of'
    );

    // These will be capitalized
    $orgstatus = array(
      'llc', 'llp', 'pllc', 'lp', 'pc', // USA
      'sa', 'sarl', 'sc', 'sci', // France
      'fze', 'fz', 'fz-llc', 'fz-co', 'rak', // UAE
      'usa', 'uae',
    );
    // These will be Firstcaped with a dot at the end
    $orgstatusSpecial = array( 'inc', 'co', 'corp', 'ltd' );

    $delimiters = array( "-", ".", "D'", "O'", "Mc", " ",);

    if (CRM_Utils_Array::value('contact_FullFirst', $this->_settings)) {
      foreach ($this->_nameFields as $field) {
        $name = CRM_Utils_Array::value($field, $contact);
        //Handle null value during Contact Merge
        if (empty($name) || ($name === "null")) {
          continue;
        }
        ///$name = mb_convert_case($name, MB_CASE_TITLE, "UTF-8");
        foreach ($delimiters as $delimiter) {
          $words = explode($delimiter, $name);
          $newWords = array();
          foreach ($words as $word) {
            if ( CRM_Utils_Array::value('contact_type', $contact) == 'Organization') {
              // Capitalize organization statuses
              // in_array is case sensitive, lower case the $word
              if ( in_array(str_replace(array('.'), '', strtolower($word)), $orgstatus) ) {
                $word = strtoupper($word);
              } else if ( in_array(str_replace(array('.'), '', strtolower($word)), $orgstatusSpecial) ) {
                // special status only need first letter to be capitalize
                $word = str_replace(array('.'), '', strtolower($word)) . '.';
              } else if (in_array(strtolower($word), $orgHandles)) {
                // lower case few matching word for Organization contact
                $word = strtolower($word);
              }
            } elseif ( CRM_Utils_Array::value('contact_type', $contact) == 'Individual') {
              // lower case few matching word for individual contact
              if (in_array(strtolower($word), $handles)) {
                $word = strtolower($word);
              }
            }
            if (!in_array($word, $handles) && !in_array($word, $orgHandles)) {
              $word = ucfirst($word);
            }
            array_push($newWords, $word);
          }
          $name = join($delimiter, $newWords);
          if (CRM_Utils_Array::value('contact_type', $contact) == 'Individual') {
            // if name is matching handles, then normalize it
            if (in_array(strtolower($name), $handles)) {
              $name = ucwords(strtolower($name));
            }
          }
        }
        $contact[$field] = $name;

      }
    }
    if (CRM_Utils_Array::value('contact_OrgCaps', $this->_settings)) {
      if ((CRM_Utils_Array::value('contact_type', $contact) == 'Organization')
        && CRM_Utils_Array::value('organization_name', $contact)
      ) {
        $contact['organization_name'] = strtoupper($contact['organization_name']);
      }
    }
    return TRUE;
  }

  /**
   * Normalizes a phone number
   * @param $phone
   *   Name that needs to be reformatted
   */

  function normalize_phone(&$phone) {
    if (empty($phone)) {
      return false;
    }
    $input = $phone['phone'];
    if (empty($input)) {
      return FALSE;
    } // Should not be empty

    $country = ($this->_country ? $this->_country : 'US');
    $phoneUtil = PhoneNumberUtil::getInstance();
    try {
      $phoneProto = $phoneUtil->parse($input, $country);
    } catch (NumberParseException $e) {
      return FALSE;
    }
    if (!$phoneUtil->isValidNumber($phoneProto)) {
      return FALSE;
    }
    if (CRM_Utils_Array::value('phone_IntlPrefix', $this->_settings)) // always give the international format
    {
      $phone['phone'] = $phoneUtil->format($phoneProto, PhoneNumberFormat::INTERNATIONAL);
    }
    elseif (CRM_Utils_Array::value('phone_normalize', $this->_settings)) // in-country for local, international for all others
    {
      if ($phoneProto->getCountryCode() == $phoneUtil->getCountryCodeForRegion($country)) {
        $phone['phone'] = $phoneUtil->format($phoneProto, PhoneNumberFormat::NATIONAL);
      }
      else {
        $phone['phone'] = $phoneUtil->format($phoneProto, PhoneNumberFormat::INTERNATIONAL);
      }
    }
    // Get phone number type and map to CiviCRM types
    $numberType = CRM_Utils_Normalize::mapPhoneNumberTypeToCivi($phoneUtil->getNumberType($phoneProto), $phone['phone_type_id']);
    if ($numberType) {
      $phone['phone_type_id'] = $numberType;
    };

    return TRUE;
  }

  /**
   * Try and map PhoneNumberType to CiviCRM phone type.  Return FALSE if we don't have a match
   *
   * @param $phoneNumberType
   * @return bool|int
   */
  function mapPhoneNumberTypeToCivi($phoneNumberType, $civiPhoneType=1) {
    // (returns array 1=>Phone,2=>Mobile etc) $phoneTypes = CRM_Core_PseudoConstant::get('CRM_Core_DAO_Phone', 'phone_type_id');
    switch ($phoneNumberType) {
      case \libphonenumber\PhoneNumberType::FIXED_LINE:
        return 1;
      case \libphonenumber\PhoneNumberType::FIXED_LINE_OR_MOBILE:
        // If it could be either, keep the civi type if set to fixed or mobile,
        // otherwise we'll select fixed_line.
        if ($civiPhoneType == 1 || $civiPhoneType == 2) {
          return $civiPhoneType;
        }
        else {
          return 1;
        }
      case \libphonenumber\PhoneNumberType::MOBILE:
        return 2;
      //CiviCRM: Fax 3
      case \libphonenumber\PhoneNumberType::PAGER:
        return 4;
      case \libphonenumber\PhoneNumberType::VOICEMAIL:
        return 5;
      default:
        return FALSE;
    }
  }

  /**
   * Normalizes a name according to International rules - as much as we can
   * Example of normalized Individual names:
   * jean-pierre DE castignac => Jean-Pierre de Castignac
   * Example of normalized Organization names:
   * it bliss, llc => It Bliss, Llc
   * IT bliss, LLC => IT Bliss, LLC
   * @param $name
   *   Name that needs to be normalized
   * @param $type
   *   Individual or Organization
   * @param $this ->_settings
   *   Options for the conversion
   */

  function normalize_address(&$address) {
    $zip_formats = array(
      'US' => '/^(\d{5})(-[0-9]{4})?$/i',
      'FR' => '/^(\d{5})$/i',
      'NL' => '/^(\d{4})\s*([a-z]{2})$/i',
      // 'AT' => '/^(\d{4})$/', there's actually a list of ~2k valid zip codes that rarely changes in Austria:
      'AT' => '/^(1010|1020|1030|1040|1050|1060|1070|1080|1090|1100|1110|1120|1130|1140|1150|1160|1170|1180|1190|1200|1210|1220|1230|1300|2000|2002|2003|2004|2011|2013|2014|2020|2022|2023|2024|2031|2032|2033|2034|2041|2042|2051|2052|2053|2054|2061|2062|2063|2064|2070|2073|2074|2081|2082|2083|2084|2091|2092|2093|2094|2095|2100|2102|2103|2104|2105|2111|2112|2113|2114|2115|2116|2120|2122|2123|2124|2125|2126|2130|2132|2133|2134|2135|2136|2141|2143|2144|2145|2151|2152|2153|2154|2161|2162|2163|2164|2165|2170|2171|2172|2181|2182|2183|2184|2185|2191|2192|2193|2201|2202|2203|2211|2212|2213|2214|2215|2221|2222|2223|2224|2225|2230|2231|2232|2241|2242|2243|2244|2245|2251|2252|2253|2261|2262|2263|2264|2265|2272|2273|2274|2275|2276|2281|2282|2283|2284|2285|2286|2291|2292|2293|2294|2295|2301|2304|2305|2320|2322|2325|2326|2331|2332|2333|2334|2340|2344|2345|2351|2352|2353|2361|2362|2371|2372|2380|2381|2384|2391|2392|2393|2401|2402|2403|2404|2405|2410|2412|2413|2421|2422|2423|2424|2425|2431|2432|2433|2434|2435|2440|2441|2442|2443|2444|2451|2452|2453|2454|2460|2462|2463|2464|2465|2471|2472|2473|2474|2475|2481|2482|2483|2485|2486|2490|2491|2492|2493|2500|2504|2511|2512|2514|2521|2522|2523|2524|2525|2531|2532|2533|2534|2540|2542|2544|2551|2552|2560|2563|2564|2565|2571|2572|2601|2602|2603|2604|2620|2624|2625|2630|2631|2632|2640|2641|2642|2650|2651|2654|2661|2662|2663|2671|2673|2680|2700|2721|2722|2723|2724|2731|2732|2733|2734|2751|2752|2753|2754|2755|2761|2763|2770|2801|2802|2803|2811|2812|2813|2821|2822|2823|2824|2831|2832|2833|2840|2842|2851|2852|2853|2860|2870|2871|2872|2873|2880|2881|3001|3002|3003|3004|3011|3012|3013|3021|3031|3032|3033|3034|3040|3041|3042|3051|3052|3053|3061|3062|3071|3072|3073|3074|3100|3104|3105|3107|3110|3121|3122|3123|3124|3125|3130|3131|3133|3134|3140|3141|3142|3143|3144|3150|3151|3153|3160|3161|3162|3163|3170|3171|3172|3180|3183|3184|3192|3193|3195|3200|3202|3203|3204|3205|3211|3212|3213|3214|3221|3222|3223|3224|3231|3232|3233|3240|3241|3242|3243|3244|3250|3251|3252|3253|3254|3261|3262|3263|3264|3270|3281|3282|3283|3291|3292|3293|3294|3295|3300|3304|3311|3312|3313|3314|3321|3322|3323|3324|3325|3331|3332|3333|3334|3335|3340|3341|3342|3343|3344|3345|3350|3351|3352|3353|3354|3355|3361|3362|3363|3364|3365|3370|3371|3372|3373|3374|3375|3376|3380|3381|3382|3383|3384|3385|3386|3390|3392|3393|3400|3413|3420|3421|3422|3423|3424|3425|3426|3430|3433|3434|3435|3441|3442|3443|3451|3452|3454|3462|3463|3464|3465|3470|3471|3472|3473|3474|3481|3482|3483|3484|3485|3491|3492|3493|3494|3495|3500|3506|3508|3511|3512|3521|3522|3524|3525|3531|3532|3533|3541|3542|3543|3544|3550|3552|3553|3561|3562|3564|3571|3572|3573|3580|3591|3592|3593|3594|3595|3601|3602|3610|3611|3613|3620|3621|3622|3623|3631|3632|3633|3641|3642|3643|3644|3650|3652|3653|3654|3660|3661|3662|3663|3664|3665|3671|3672|3680|3681|3683|3684|3691|3701|3702|3704|3710|3711|3712|3713|3714|3720|3721|3722|3730|3741|3742|3743|3744|3751|3752|3753|3754|3761|3762|3763|3800|3804|3811|3812|3813|3814|3820|3822|3823|3824|3830|3834|3841|3842|3843|3844|3851|3852|3860|3861|3862|3863|3871|3872|3873|3874|3900|3902|3903|3910|3911|3912|3913|3914|3920|3921|3922|3923|3924|3925|3931|3932|3942|3943|3944|3945|3950|3961|3962|3970|3971|3972|3973|4020|4030|4040|4048|4050|4052|4053|4055|4060|4061|4062|4063|4064|4070|4072|4073|4074|4075|4076|4081|4082|4083|4084|4085|4090|4091|4092|4100|4101|4102|4111|4112|4113|4114|4115|4116|4120|4121|4122|4131|4132|4133|4134|4141|4142|4143|4144|4150|4151|4152|4153|4154|4155|4160|4161|4162|4163|4164|4170|4171|4172|4173|4174|4175|4180|4181|4182|4183|4184|4190|4191|4192|4193|4201|4202|4203|4204|4209|4210|4211|4212|4221|4222|4223|4224|4225|4230|4232|4240|4242|4251|4252|4261|4262|4263|4264|4271|4272|4273|4274|4280|4281|4282|4283|4284|4291|4292|4293|4294|4300|4303|4310|4311|4312|4320|4322|4323|4324|4331|4332|4341|4342|4343|4351|4352|4360|4362|4363|4364|4371|4372|4381|4382|4391|4392|4400|4407|4421|4431|4432|4441|4442|4443|4451|4452|4453|4460|4461|4462|4463|4464|4470|4481|4482|4483|4484|4490|4491|4492|4493|4501|4502|4511|4521|4522|4523|4531|4532|4533|4540|4541|4542|4550|4551|4552|4553|4554|4560|4562|4563|4564|4565|4571|4572|4573|4574|4575|4580|4581|4582|4591|4592|4593|4594|4595|4596|4600|4611|4612|4613|4614|4615|4616|4621|4622|4623|4624|4625|4631|4632|4633|4641|4642|4643|4644|4645|4650|4651|4652|4653|4654|4655|4656|4661|4662|4663|4664|4671|4672|4673|4674|4675|4676|4680|4681|4682|4690|4691|4692|4693|4694|4701|4702|4707|4710|4712|4713|4714|4715|4716|4720|4721|4722|4723|4724|4725|4730|4731|4732|4733|4741|4742|4743|4751|4752|4753|4754|4755|4760|4761|4762|4770|4771|4772|4773|4774|4775|4776|4777|4780|4782|4783|4784|4785|4786|4791|4792|4793|4794|4800|4801|4802|4810|4812|4813|4814|4816|4817|4820|4821|4822|4823|4824|4825|4830|4831|4840|4841|4842|4843|4844|4845|4846|4849|4850|4851|4852|4853|4854|4860|4861|4863|4864|4865|4866|4870|4871|4872|4873|4880|4881|4882|4890|4891|4892|4893|4894|4901|4902|4903|4904|4906|4910|4911|4912|4920|4921|4922|4923|4924|4925|4926|4931|4932|4933|4941|4942|4943|4950|4951|4952|4961|4962|4963|4970|4971|4972|4973|4974|4975|4980|4981|4982|4983|4984|5020|5023|5026|5061|5071|5081|5082|5083|5084|5090|5091|5092|5093|5101|5102|5110|5111|5112|5113|5114|5120|5121|5122|5131|5132|5133|5134|5141|5142|5143|5144|5145|5151|5152|5161|5162|5163|5164|5165|5166|5201|5202|5203|5204|5205|5211|5212|5221|5222|5223|5224|5225|5230|5231|5232|5233|5241|5242|5251|5252|5261|5270|5271|5272|5273|5274|5280|5282|5300|5301|5302|5303|5310|5311|5321|5322|5323|5324|5325|5330|5340|5342|5350|5351|5360|5400|5411|5412|5421|5422|5423|5424|5425|5431|5440|5441|5442|5450|5451|5452|5453|5500|5505|5511|5521|5522|5523|5524|5531|5532|5541|5542|5550|5552|5561|5562|5563|5570|5571|5572|5573|5574|5575|5580|5581|5582|5583|5584|5585|5591|5592|5600|5602|5603|5611|5612|5620|5621|5622|5630|5632|5640|5645|5651|5652|5660|5661|5662|5671|5672|5700|5710|5721|5722|5723|5724|5730|5731|5732|5733|5741|5742|5743|5751|5752|5753|5754|5760|5761|5771|6020|6060|6063|6065|6067|6068|6069|6070|6071|6072|6073|6074|6075|6080|6082|6083|6091|6092|6094|6095|6100|6103|6105|6108|6111|6112|6113|6114|6115|6116|6121|6122|6123|6130|6133|6134|6135|6136|6141|6142|6143|6145|6150|6152|6154|6156|6157|6161|6162|6165|6166|6167|6170|6173|6175|6176|6178|6179|6181|6182|6183|6184|6200|6210|6212|6213|6215|6220|6222|6230|6232|6233|6234|6235|6236|6240|6241|6250|6252|6260|6261|6262|6263|6264|6265|6271|6272|6273|6274|6275|6276|6277|6278|6280|6281|6283|6284|6290|6292|6293|6294|6295|6300|6305|6306|6311|6313|6314|6320|6321|6322|6323|6324|6330|6334|6335|6336|6341|6342|6343|6344|6345|6346|6347|6351|6352|6353|6361|6363|6364|6365|6370|6371|6372|6373|6380|6382|6383|6384|6385|6391|6392|6393|6395|6401|6402|6403|6404|6405|6406|6408|6410|6413|6414|6416|6421|6422|6423|6424|6425|6426|6430|6432|6433|6441|6444|6450|6452|6456|6458|6460|6462|6463|6464|6465|6471|6473|6474|6481|6491|6492|6493|6500|6511|6521|6522|6524|6525|6526|6527|6528|6531|6532|6533|6534|6541|6542|6543|6544|6551|6552|6553|6555|6561|6562|6563|6571|6572|6574|6580|6591|6600|6604|6610|6611|6621|6622|6623|6631|6632|6633|6642|6644|6645|6646|6647|6650|6651|6652|6653|6654|6655|6670|6671|6672|6673|6675|6677|6682|6691|6700|6706|6707|6708|6710|6712|6713|6714|6719|6721|6722|6723|6731|6733|6741|6751|6752|6754|6762|6763|6764|6767|6771|6773|6774|6780|6781|6782|6787|6791|6793|6794|6800|6811|6812|6820|6822|6824|6830|6832|6833|6834|6835|6836|6837|6840|6841|6842|6844|6845|6850|6858|6861|6863|6866|6867|6870|6874|6881|6882|6883|6884|6886|6888|6890|6900|6911|6912|6914|6921|6922|6923|6932|6933|6934|6941|6942|6943|6951|6952|6960|6971|6972|6973|6974|6991|6992|6993|7000|7011|7012|7013|7020|7021|7022|7023|7024|7031|7032|7033|7034|7035|7041|7042|7051|7052|7053|7061|7062|7063|7064|7071|7072|7081|7082|7083|7091|7092|7093|7100|7111|7121|7122|7123|7131|7132|7141|7142|7143|7151|7152|7161|7162|7163|7201|7202|7203|7210|7212|7221|7222|7223|7301|7302|7304|7311|7312|7321|7322|7323|7331|7332|7341|7342|7343|7344|7350|7361|7371|7372|7373|7374|7400|7410|7411|7412|7421|7422|7423|7431|7432|7433|7434|7435|7441|7442|7443|7444|7451|7452|7453|7461|7463|7464|7471|7472|7473|7474|7501|7503|7511|7512|7521|7522|7531|7532|7533|7534|7535|7536|7537|7540|7542|7543|7544|7551|7552|7561|7562|7563|7564|7571|7572|8010|8020|8036|8041|8042|8043|8044|8045|8046|8047|8051|8052|8053|8054|8055|8061|8062|8063|8071|8072|8073|8074|8075|8076|8077|8081|8082|8083|8091|8092|8093|8101|8102|8103|8111|8112|8113|8114|8120|8121|8122|8124|8130|8131|8132|8141|8142|8143|8144|8151|8152|8153|8160|8162|8163|8171|8172|8181|8182|8183|8184|8190|8191|8192|8200|8211|8212|8221|8222|8223|8224|8225|8230|8232|8233|8234|8240|8241|8242|8243|8244|8250|8251|8252|8253|8254|8255|8261|8262|8263|8264|8265|8271|8272|8273|8274|8280|8282|8283|8291|8292|8293|8294|8295|8301|8302|8311|8312|8313|8321|8322|8323|8324|8330|8332|8333|8334|8341|8342|8343|8344|8345|8350|8352|8353|8354|8355|8361|8362|8380|8382|8383|8384|8385|8401|8402|8403|8410|8411|8412|8413|8421|8422|8423|8424|8430|8431|8435|8441|8442|8443|8444|8451|8452|8453|8454|8455|8461|8462|8463|8471|8472|8473|8480|8481|8483|8484|8490|8492|8493|8501|8502|8503|8504|8505|8510|8511|8521|8522|8523|8524|8530|8541|8542|8543|8544|8551|8552|8553|8554|8561|8562|8563|8564|8565|8570|8572|8573|8580|8582|8583|8584|8591|8592|8593|8600|8605|8611|8612|8614|8616|8621|8622|8623|8624|8625|8630|8632|8634|8635|8636|8641|8642|8643|8644|8650|8652|8653|8654|8661|8662|8663|8664|8665|8670|8671|8672|8673|8674|8680|8682|8684|8685|8691|8692|8693|8694|8700|8712|8713|8714|8715|8720|8723|8724|8731|8732|8733|8734|8740|8741|8742|8750|8753|8754|8755|8756|8761|8762|8763|8764|8765|8770|8772|8773|8774|8775|8781|8782|8783|8784|8785|8786|8790|8792|8793|8794|8795|8800|8811|8812|8813|8820|8822|8831|8832|8833|8841|8842|8843|8844|8850|8852|8853|8854|8861|8862|8863|8864|8900|8903|8904|8911|8912|8913|8920|8921|8922|8923|8924|8931|8932|8933|8934|8940|8942|8943|8950|8951|8952|8953|8954|8960|8961|8962|8965|8966|8967|8970|8971|8972|8973|8974|8982|8983|8984|8990|8992|8993|9020|9061|9062|9063|9064|9065|9071|9072|9073|9074|9081|9082|9100|9102|9103|9111|9112|9113|9121|9122|9123|9125|9130|9131|9132|9133|9135|9141|9142|9143|9150|9155|9161|9162|9163|9170|9173|9181|9182|9183|9184|9201|9210|9212|9220|9231|9232|9241|9300|9311|9312|9313|9314|9321|9322|9323|9330|9334|9335|9341|9342|9343|9344|9345|9346|9360|9361|9362|9363|9371|9372|9373|9374|9375|9376|9400|9411|9412|9413|9421|9422|9423|9431|9433|9441|9451|9461|9462|9463|9470|9472|9473|9500|9504|9520|9521|9523|9524|9530|9531|9535|9536|9541|9542|9543|9544|9545|9546|9551|9552|9554|9555|9556|9560|9562|9563|9564|9565|9570|9571|9572|9580|9581|9582|9583|9584|9585|9586|9587|9601|9602|9611|9612|9613|9614|9615|9620|9622|9623|9624|9631|9632|9633|9634|9635|9640|9651|9652|9653|9654|9655|9701|9702|9710|9711|9712|9713|9714|9721|9722|9751|9753|9754|9761|9762|9771|9772|9773|9781|9782|9800|9805|9811|9812|9813|9814|9815|9816|9821|9822|9831|9832|9833|9841|9842|9843|9844|9851|9852|9853|9854|9861|9862|9863|9871|9872|9873|9900|9903|9904|9905|9906|9907|9908|9909|9911|9912|9913|9918|9919|9920|9931|9932|9941|9942|9943|9951|9952|9954|9961|9962|9963|9971|9972|9974|9981|9990|9991|9992)$/',
      'DE' => '/^(\d{5})$/',
    );
    // These will be all-capped
    $directionals = array(
      'ne', 'nw', 'se', 'sw',
    );
    if ($value = CRM_Utils_Array::value('address_CityCaps', $this->_settings)) {
      $city = CRM_Utils_Array::value('city', $address);
      if ($value == 1 && $city) {
        $address['city'] = strtoupper($city);
      } elseif($value == 2 && $city) {
        $address['city'] = ucwords(strtolower($city));
      }
    }
    if ($value = CRM_Utils_Array::value('address_StreetCaps', $this->_settings)) {
      foreach( array('street_address','supplemental_address_1', 'supplemental_address_2') as $name) {
        $addressValue = CRM_Utils_Array::value($name, $address);
        if ($value == 1 && $addressValue) {
          $address[$name] = strtoupper($addressValue);
        } elseif($value == 2 && $name) {
          $address[$name] = ucwords(strtolower($addressValue));
          $patterns = array();
          foreach ($directionals as $d) {
            $patterns[] = "/\\b$d\\b/i";
          }
          $address[$name] = preg_replace_callback($patterns, function($matches){return strtoupper($matches[0]);}, $address[$name]);
        }
      }
    }
    if (CRM_Utils_Array::value('address_Zip', $this->_settings)) {
      if (($zip = CRM_Utils_Array::value('postal_code', $address))
        && ($cid = CRM_Utils_Array::value('country_id', $address))) {
        $codes = CRM_Core_PseudoConstant::countryIsoCode();
        if ($regex = CRM_Utils_Array::value($codes[$cid], $zip_formats)) {
          if (!preg_match($regex, $zip, $matches)) {
            CRM_Core_Session::setStatus(ts('Invalid Zip Code format %1', array(1 => $zip)));
          }
        }
      }
    }

    return TRUE;
  }

  function getNameFields() {
    return $this->_nameFields;
  }

  function getAddressFields() {
    return $this->_addressFields;
  }

  function getPhoneFields() {
    return $this->_phoneFields;
  }

  static function processNormalization($fromContactId, $toContactId, $dryRun=FALSE, $logFile=NULL) {
    $processInfo = array('name' => 0, 'phone' => 0, 'address' => 0);
    if (empty($fromContactId) || empty($toContactId)) {
      return $processInfo;
    }

    $contactIds = range($fromContactId, $toContactId);

    $normalization = CRM_Utils_Normalize::singleton();

    $formattedContactIds = $formattedPhoneIds = $formattedAddressIds = array();
    foreach ($contactIds as $contactId) {
      $contact = new CRM_Contact_DAO_Contact();
      $contact->id = $contactId;
      if ($contact->find()) {
        $params = array('id' => $contactId, 'contact_id' => $contactId);
        $orgContactValues = array();
        CRM_Contact_BAO_Contact::retrieve($params, $orgContactValues);
        //update contacts name fields.
        $formatNameValues = array();
        foreach ($normalization->getNameFields() as $field) {
          $nameValue = CRM_Utils_Array::value($field, $orgContactValues);
          if (empty($nameValue)) {
            continue;
          }
          $formatNameValues[$field] = $nameValue;
        }
        if (!empty($formatNameValues)) {
          $formatNameValues['contact_type'] = $orgContactValues['contact_type'];
          $formattedNameValues = $formatNameValues;

          //format name values
          $normalization->normalize_contact($formattedNameValues);

          //check formatted diff, only update if there is difference.
          $formatDiff = array_diff($formatNameValues, $formattedNameValues);
          if (!empty($formatDiff)) {
            $formattedNameValues['id'] = $formattedNameValues['contact_id'] = $orgContactValues['id'];
            $formattedNameValues['contact_type'] = $orgContactValues['contact_type'];
            if ($dryRun) {
              // Record for stats
              $formattedContactIds[$contactId] = $contactId;
            }
            else {
              $contactUpdated = CRM_Contact_BAO_Contact::add($formattedNameValues);
              if ($contactUpdated->id) {
                $formattedContactIds[$contactUpdated->id] = $contactUpdated->id;
              }
              $contactUpdated->free();
            }
            if (!empty($logFile)) {
              // Log change
              CRM_Utils_Normalize::logNormalization('name', $orgContactValues, $formattedNameValues, $logFile);
            }
          }
        }

        //update phone fields.
        if (isset($orgContactValues['phone']) && is_array($orgContactValues['phone'])) {
          foreach ($orgContactValues['phone'] as $cnt => $orgPhoneValues) {
            if (!isset($orgPhoneValues['id']) || empty($orgPhoneValues['id']) || empty($orgPhoneValues['phone'])) {
              continue;
            }

            $formattedPhoneValues = $orgPhoneValues;

            //format phone fields
            $normalization->normalize_phone($formattedPhoneValues);

            //do check for formatted difference, than only update.
            $formattedDiff = array_diff_assoc($orgPhoneValues, $formattedPhoneValues);
            if (!empty($formattedDiff)) {
              if ($dryRun) {
                // Record for stats
                $formattedPhoneIds[$orgPhoneValues['id']] = $orgPhoneValues['id'];
              }
              else {
                $phoneUpdated = CRM_Core_BAO_Phone::add($formattedPhoneValues);
                if ($phoneUpdated->id) {
                  $formattedPhoneIds[$phoneUpdated->id] = $phoneUpdated->id;
                }
                $phoneUpdated->free();
              }
              if (!empty($logFile)) {
                // Log change
                CRM_Utils_Normalize::logNormalization('phone', $orgPhoneValues, $formattedPhoneValues, $logFile);
              }
            }
          }
        }

        //update address.
        if (isset($orgContactValues['address']) && is_array($orgContactValues['address'])) {
          foreach ($orgContactValues['address'] as $cnt => $orgAddressValues) {
            if (!isset($orgAddressValues['id']) || empty($orgAddressValues['id'])) {
              continue;
            }

            $formattedAddressValues = $orgAddressValues;

            //format addrees fields
            $normalization->normalize_address($formattedAddressValues);

            //do check for formatted difference, than only update.
            $formattedDiff = array_diff($orgAddressValues, $formattedAddressValues);
            if (!empty($formattedDiff)) {
              if ($dryRun) {
                // Record for stats
                $formattedAddressIds[$orgAddressValues['id']] = $orgAddressValues['id'];
              }
              else {
                $addressUpdated = CRM_Core_BAO_Address::add($formattedAddressValues, FALSE);
                if ($addressUpdated->id) {
                  $formattedAddressIds[$addressUpdated->id] = $addressUpdated->id;
                }
                $addressUpdated->free();
              }
              if (!empty($logFile)) {
                // Log change
                CRM_Utils_Normalize::logNormalization('address', $orgAddressValues, $formattedAddressValues, $logFile);
              }
            }
          }
        }
      }
      $contact->free();
    }

    $processInfo = array(
      'name' => $formattedContactIds,
      'phone' => $formattedPhoneIds,
      'address' => $formattedAddressIds
    );

    return $processInfo;
  }

  /**
   * Function to log normalization changes
   * CSV Format:
   * contactid,phone,field1,old,new,field2,old,new,field3...
   * contactid,address,...
   * contactid,name,...
   *
   * Currently just writes out to debug log.
   * @param $type : eg. "address", "phone", "name"
   * @param $origValues : array of original values
   * @param $formattedValues : array of modified values
   */
  static function logNormalization($type, $origValues, $formattedValues, $logFile) {
    // For some types, eg. name $formattedValues may only contain a subset of $origValues
    // So we merge the arrays to get new values, old values:
    // Take new values in merged array
    $newValues = array_merge($origValues, $formattedValues);
    // Take old values in merged array
    $oldValues = array_merge($formattedValues, $origValues);
    // Get all changed values
    $newValuesChanged = array_diff($newValues, $oldValues);
    // contactid,type
    $csv = $formattedValues['contact_id'].','.$type;
    foreach($newValuesChanged as $field => $value) {
      // Build fields string
      // Format is field,oldvalue,newvalue
      $csv .= ','.$field.','.$oldValues[$field].','.$value;
    }

    // Write to a file in ConfigAndLogDir
    $config = CRM_Core_Config::singleton();
    if (!empty($config->configAndLogDir)) {
      $file = $config->configAndLogDir ."/$logFile";
      file_put_contents($file, $csv.PHP_EOL, FILE_APPEND | LOCK_EX);
    }
  }

  /**
   * Delete log file if it exists
   * @param $logFile
   */
  static function deleteLogFile($logFile) {
    $config = CRM_Core_Config::singleton();
    if (!empty($config->configAndLogDir) && !empty($logFile)) {
      $file = $config->configAndLogDir . "/$logFile";
      if (file_exists($file)) {
        unlink($file);
      }
    }
  }

  static function downloadLogFile($logFile)
  {
    $config = CRM_Core_Config::singleton();
    if (!empty($config->configAndLogDir) && !empty($logFile)) {
      $fullPath = $config->configAndLogDir . "/$logFile";

      ignore_user_abort(true);
      set_time_limit(0); // disable the time limit for this script

      if ($fd = fopen($fullPath, "r")) {
        $fsize = filesize($fullPath);
        $path_parts = pathinfo($fullPath);
        $ext = strtolower($path_parts["extension"]);
            header("Content-type: application/octet-stream");
            header("Content-Disposition: filename=\"" . $path_parts["basename"] . "\"");
        header("Content-length: $fsize");
        header("Cache-control: private"); //use this to open files directly
        while (!feof($fd)) {
          $buffer = fread($fd, 2048);
          echo $buffer;
        }
      }
      fclose($fd);
      exit;
    }
    else {
      exit;
    }
  }
}
