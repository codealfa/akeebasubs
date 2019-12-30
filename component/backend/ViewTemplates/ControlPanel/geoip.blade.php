<?php
/**
 *  @package AkeebaSubs
 *  @copyright Copyright (c)2010-2019 Nicholas K. Dionysopoulos / Akeeba Ltd
 *  @license GNU General Public License version 3, or later
 */

defined('_JEXEC') or die;
?>

@section('geoip')
    @if (!$this->hasGeoIPPlugin)
        <div class="akeeba-block--info">
            <h3>
                @lang('COM_AKEEBASUBS_GEOIP_LBL_GEOIPPLUGINSTATUS')
            </h3>

            <p>
                @lang('COM_AKEEBASUBS_GEOIP_LBL_GEOIPPLUGINMISSING')
            </p>

            <a class="akeeba-btn--primary--small" href="https://www.akeebabackup.com/download/akgeoip.html" target="_blank">
                <span class="akion-ios-download-outline"></span>
                @lang('COM_AKEEBASUBS_GEOIP_LBL_DOWNLOADGEOIPPLUGIN')
            </a>
        </div>
    @endif
@stop
