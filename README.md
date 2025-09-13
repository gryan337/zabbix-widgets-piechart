# zabbix-widgets-graph
An enhanced Pie Chart Widget for the Zabbix Dashboard

## Functionality and Features
- Adds a percentage of the pie in the tooltip when hovering over a pie chart sector. This is important to show the percentage of the total that each metric comprises.
- Adds the ability to use built-in and usermacros in the Data set label field. By default, the pie chart widget presents each metric as "HOSTNAME: ITEMNAME". For example, you can transform your metric to just be the metric name by typing {ITEM.NAME} in the Data set label box. Additionally, the full range of macro functions are supported. For example, with a item name of "CPU Usage - MyProcess", you could change the legend value by doing something like this: {{ITEM.NAME}.regrepl("CPU Usage - ", "")} and it will display as "MyProcess"
- Adds the ability to aggregate by item name. This works hand-in-hand with the added ability to use macros in the Data set label. This makes for an incredibly powerful experience and permits new ways to aggregate data in the pie chart.

## Disruptively Innovative Modifications
- This widget has been modified to accept multiple itemids broadcasted from certain widgets in the gryan337 git repository. As of right now the zabbix-widgets-itemnavigator module can broadcast multiple itemds. Soon, the Table widget will be able to (with significant advanced interactive functionality). More documentation coming soon!


# 🚀 Project Roadmap

A high-level view of our project milestones and upcoming goals.

---

## 📍 September 2025

- [ ] Basic documentation written along with screen shots of the enhancements  
- [ ] Final QA & bug fixes (please submit bugs!)  

---

## 🛠️ Upcoming (Q4 2025)

| Milestone | Status | Target |
|-----------|--------|--------|
| Crowd sourced feature requests | Upcoming | Q4 2025 |
