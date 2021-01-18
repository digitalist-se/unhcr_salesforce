BankData.php

Validation for bank accounts

CharityService.php

Batched service for donations.


Date formats: https://developer.salesforce.com/docs/atlas.en-us.soql_sosl.meta/soql_sosl/sforce_api_calls_soql_select_dateformats.htm


UTM:
html.hml.twig

checks utm codes and posts to /client-id
ClientController => tempstore
DirectCheckoutFormBase -> to the commerce order
Then datalayer sendAnalytics to GTM


    if (ENVIRONMENT == 'PROD') {
      $host = 'http://sverigeforunhcr.se';
    }
    elseif (ENVIRONMENT == 'STAGE') {
      $host = 'https://sverigeforunhcr.dgstage.se';
    }
    else {
      $host = 'http://und8.lndo.site';
    }
