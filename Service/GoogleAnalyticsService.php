<?php

namespace RocketServices\GoogleAnalyticsApi\Service;

use Google_Client;
use Google_Service_AnalyticsReporting;
use Google_Service_AnalyticsReporting_DateRange;
use Google_Service_AnalyticsReporting_GetReportsRequest;
use Google_Service_AnalyticsReporting_Metric;
use Google_Service_AnalyticsReporting_Dimension;
use Google_Service_AnalyticsReporting_ReportRequest;
use Symfony\Component\Config\Definition\Exception\Exception;

/**
 * Class GoogleAnalyticsService
 * @package RocketServices\GoogleAnalyticsApi\Service
 */
class GoogleAnalyticsService {

    /**
     * @var Google_Client
     */
    private $client;
    /**
     * @var Google_Service_AnalyticsReporting
     */
    private $analytics;

    /**
     * construct
     */
    public function __construct($keyFileLocation) {

        if (!file_exists($keyFileLocation)) {
            throw new Exception("can't find file key location defined by google_analytics_api.google_analytics_json_key parameter, ex : ../data/analytics/analytics-key.json");
        }

        $this->client = new Google_Client();
        $this->client->setApplicationName("GoogleAnalytics");
        $this->client->setScopes(['https://www.googleapis.com/auth/analytics.readonly']);
        $this->client->setAuthConfig($keyFileLocation);

        $this->analytics = new Google_Service_AnalyticsReporting($this->client);

    }

    /**
     * @return Google_Service_AnalyticsReporting
     */
    public function getAnalytics() {

        return $this->analytics;

    }

    /**
     * @return Google_Client
     */
    public function getClient() {

        return $this->client;

    }

    /**
     * @param $viewId
     * @param $dateStart
     * @param $dateEnd
     * @param $metricExpression
     * @param $dimensionExpression
     * @return mixed
     */
    private function getDataDateRange($viewId,$dateStart,$dateEnd,$metricExpressions,$dimensionExpressions = array()) {

        // Create the DateRange object
        $dateRange = new Google_Service_AnalyticsReporting_DateRange();
        $dateRange->setStartDate($dateStart);
        $dateRange->setEndDate($dateEnd);

        // Create the Metric objects array
        $metrics = $this->getMetrics($metricExpressions);

        // Create the Dimension objects array
        $dimensions = $this->getDimensions($dimensionExpressions);

        // Create the ReportRequest object
        $request = new Google_Service_AnalyticsReporting_ReportRequest();
        $request->setViewId($viewId);
        $request->setDateRanges($dateRange);
        $request->setMetrics($metrics);
        $request->setDimensions($dimensions);

        $body = new Google_Service_AnalyticsReporting_GetReportsRequest();
        $body->setReportRequests([$request]);

        $reports = $this->analytics->reports->batchGet($body);

        return $this->formatDimensionsByDate($reports[0]);
    }

    /**
     * @param $metricExpressions
     * @return mixed
     */
    private function getMetrics($metricExpressions)
    {
        $metrics = array();
        if (is_array($metricExpressions)) {
            foreach ($metricExpressions as $expression) {
                $metrics[] = $this->createMetric($expression);
            }
        } else {
            $metrics[] = $this->createMetric($metricExpressions);
        }

        return $metrics;
    }

    /**
     * @param $expression
     * @return mixed
     */
    private function createMetric($expression)
    {
        $metric = new Google_Service_AnalyticsReporting_Metric();
        $metric->setExpression("ga:$expression");
        $metric->setAlias($expression);

        return $metric;
    }

    /**
     * @param $dimensionExpressions
     * @return mixed
     */
    private function getDimensions($dimensionExpressions)
    {
        if (is_array($dimensionExpressions)) {
            foreach ($dimensionExpressions as $expression) {
                $dimensions[] = $this->createDimension($expression);
            }
        } else {
            $dimensions[] = $this->createDimension($dimensionExpressions);
        }

        return $dimensions;
    }

    /**
     * @param $expression
     * @return mixed
     */
    private function createDimension($expression)
    {
        $dimension = new Google_Service_AnalyticsReporting_Dimension();
        $dimension->setName("ga:$expression");

        return $dimension;
    }

    /**
     * cc LHD ;)
     * @param $report
     * @return mixed
     */
    private function formatDimensionsByDate($report)
    {
        // Set the returned array
        $res = array();

        // Set the metrics/dimensions label
        $header = $report->getColumnHeader();
        $dimensionHeaders = $header->getDimensions();
        $metricHeaders = $header->getMetricHeader()->getMetricHeaderEntries();

        foreach ($dimensionHeaders as $dimension) {
            $res['dimensionNames'][] = mb_strcut($dimension, 3);
        }

        foreach ($metricHeaders as $metric) {
            $res['metricNames'][] = $metric->getName();
        }

        $res['dateRangeValues'] = [
            'total' => $report->getData()->getTotals()[0]['values'][0],
            'min' => $report->getData()->getMinimums()[0]['values'][0],
            'max' => $report->getData()->getMaximums()[0]['values'][0],
            'totalByDimensions' => [],
        ];

        // Format the returned result from ga if there is some results
        if ($report->getData()['rowCount'] > 0)
        {
            $rows = $report->getData()->getRows();

            for ( $rowIndex = 0; $rowIndex < count($rows); $rowIndex++) {
                $row = $rows[$rowIndex];
                $dimensions = $row->getDimensions();
                $metrics = $row->getMetrics();

                if (count($dimensionHeaders) > 1) {
                    for ($i = 1; $i <= count($dimensionHeaders) && $i < count($dimensions); $i++) {
                        $res['dimensions'][$dimensions[0]][$dimensions[$i]] = $metrics[0]->getValues()[0];
                        if (!array_key_exists($dimensions[$i], $res['dateRangeValues']['totalByDimensions'])) {
                            $res['dateRangeValues']['totalByDimensions'][$dimensions[$i]] = 0;
                        }
                        $res['dateRangeValues']['totalByDimensions'][$dimensions[$i]] += $metrics[0]->getValues()[0];

                        $tmpMetrics[$rowIndex] = [$metrics[0]->getValues()];
                        $res['metrics'][$rowIndex] = $tmpMetrics[$rowIndex][0][0];
                    }
                } else {
                    for ($i = 0; $i < count($dimensionHeaders) && $i < count($dimensions); $i++) {
                        $res['dimensions'][$dimensions[0]]=$metrics[0]->getValues()[0];
                        $tmpMetrics[$rowIndex] = [$metrics[0]->getValues()];
                        $res['metrics'][$rowIndex] = $tmpMetrics[$rowIndex][0][0];
                    }
                }
            }
        } else {
            $res['dimensions'] = [0];
            $res['metrics'] = [0];
        }

        return $res;
    }

    /**
     * @param $viewId
     * @param $dateStart
     * @param $dateEnd
     * @param $metric
     * @return mixed
     */
    public function getMetricDateRange($viewId,$dateStart,$dateEnd,$metric) {
        return $this->getDataDateRange($viewId,$dateStart,$dateEnd,$metric,'date');
    }

    /**
     * @param $viewId
     * @param $dateStart
     * @param $dateEnd
     * @param $metric
     * @return mixed
     */
    public function getMetricByDimensionDateRange($viewId,$dateStart,$dateEnd,$metric,$dimension) {
        return $this->getDataDateRange($viewId,$dateStart,$dateEnd,$metric,['date',$dimension]);
    }

    /**
     * @param $viewId
     * @param $dateStart
     * @param $dateEnd
     * @return mixed
     */
    public function getSessionsDateRange($viewId,$dateStart,$dateEnd) {
        return $this->getDataDateRange($viewId,$dateStart,$dateEnd,'sessions','date');
    }

    /**
     * @param $viewId
     * @param $dateStart
     * @param $dateEnd
     * @return mixed
     */
    public function getSessionsPerDeviceDateRange($viewId,$dateStart,$dateEnd) {
        return $this->getDataDateRange($viewId,$dateStart,$dateEnd,'sessions',['date','deviceCategory']);
    }

    /**
     * @param $viewId
     * @param $dateStart
     * @param $dateEnd
     * @return mixed
     */
    public function getBounceRateDateRange($viewId,$dateStart,$dateEnd) {
        return $this->getDataDateRange($viewId,$dateStart,$dateEnd,'bounceRate','date');
    }

    /**
     * @param $viewId
     * @param $dateStart
     * @param $dateEnd
     * @return mixed
     */
    public function getAvgTimeOnPageDateRange($viewId,$dateStart,$dateEnd) {
        return $this->getDataDateRange($viewId,$dateStart,$dateEnd,'avgTimeOnPage','date');
    }

    /**
     * @param $viewId
     * @param $dateStart
     * @param $dateEnd
     * @return mixed
     */
    public function getPageviewsPerSessionDateRange($viewId,$dateStart,$dateEnd) {
        return $this->getDataDateRange($viewId,$dateStart,$dateEnd,'pageviewsPerSession','date');
    }

    /**
     * @param $viewId
     * @param $dateStart
     * @param $dateEnd
     * @return mixed
     */
    public function getPercentNewVisitsDateRange($viewId,$dateStart,$dateEnd) {
        return $this->getDataDateRange($viewId,$dateStart,$dateEnd,'percentNewVisits','date');
    }

    /**
     * @param $viewId
     * @param $dateStart
     * @param $dateEnd
     * @return mixed
     */
    public function getPageViewsDateRange($viewId,$dateStart,$dateEnd) {
        return $this->getDataDateRange($viewId,$dateStart,$dateEnd,'pageviews','date');
    }

    /**
     * @param $viewId
     * @param $dateStart
     * @param $dateEnd
     * @return mixed
     */
    public function getAvgPageLoadTimeDateRange($viewId,$dateStart,$dateEnd) {
        return $this->getDataDateRange($viewId,$dateStart,$dateEnd,'avgPageLoadTime','date');
    }

    /**
     * @param $viewId
     * @param $dateStart
     * @param $dateEnd
     * @return mixed
     */
    public function getAvgOrderValueDateRange($viewId,$dateStart,$dateEnd) {
        return $this->getDataDateRange($viewId,$dateStart,$dateEnd,'revenuePerTransaction','date');
    }

    /**
     * @param $viewId
     * @param $dateStart
     * @param $dateEnd
     * @return mixed
     */
    public function getAvgOrderValuePerDeviceDateRange($viewId,$dateStart,$dateEnd) {
        return $this->getDataDateRange($viewId,$dateStart,$dateEnd,'revenuePerTransaction',['date','deviceCategory']);
    }
}
